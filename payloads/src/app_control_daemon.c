/*
 * PS4 Manager Tools - App Control Daemon
 * Servidor TCP residente en puerto 9091 para GoldHEN (FW 9.00 / 11.00)
 * Permite consultar estado de app activa, cerrar apps y lanzar juegos/apps.
 */

#include <stdio.h>
#include <stdint.h>
#include <stdbool.h>
#include <string.h>
#include <stdlib.h>
#include <unistd.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <sys/time.h>
#include <signal.h>
#include <sys/sysctl.h>
#include <sys/user.h>

#define DAEMON_PORT 9091
#define BUFFER_SIZE 4096
#define DAEMON_VERSION "1.1.0"
#define MAX_LOGIN_USERS 4
#define USER_ID_INVALID 0xff

/* Orbis Notification API */
typedef struct {
    char unused[45];
    char message[3075];
} CaNotifyRequest;

int32_t sceKernelSendNotificationRequest(int32_t device, void *req, size_t size, int32_t blocking);

/* libSceNet: initialize the network stack and reserve its socket pool before
 * using POSIX socket APIs. GoldHEN's BinLoader can start this ELF without the
 * network runtime having been initialized for the payload. */
int32_t sceNetCtlInit(void);
int32_t sceNetInit(void);
int32_t sceNetPoolCreate(const char *name, size_t size, int flags);

/* Orbis System Service API */
int32_t sceSystemServiceGetAppIdOfBigApp(void);
int32_t sceSystemServiceKillApp(uint32_t appid, int32_t opt, int32_t method, int32_t reason);
int32_t sceLncUtilGetAppTitleId(uint32_t appid, char *title_id);

/* UserService: never launch under user_id 0 by default. */
typedef struct {
    int32_t userId[MAX_LOGIN_USERS];
} OrbisUserServiceLoginUserIdList;
int32_t sceUserServiceInitialize(void *params);
int32_t sceUserServiceGetForegroundUser(int32_t *user_id);
int32_t sceUserServiceGetLoginUserIdList(OrbisUserServiceLoginUserIdList *list);
int32_t sceUserServiceGetUserName(int32_t user_id, char *name, size_t name_size);

/* Orbis Launch API */
typedef struct _LncAppParam {
    uint32_t size;
    uint32_t user_id;
    uint32_t app_opt;
    uint64_t crash_report;
    uint32_t LaunchAppCheck_flag;
} LncAppParam;

int32_t sceLncUtilLaunchApp(const char *title_id, const char *argv[], LncAppParam *param);

static bool g_user_service_initialized = false;

static bool ensure_user_service(void) {
    if (g_user_service_initialized) return true;
    if (sceUserServiceInitialize(NULL) != 0) return false;
    g_user_service_initialized = true;
    return true;
}

static bool get_logged_in_users(OrbisUserServiceLoginUserIdList *list) {
    if (!list || !ensure_user_service()) return false;
    memset(list, 0xff, sizeof(*list));
    return sceUserServiceGetLoginUserIdList(list) == 0;
}

static void json_escape_string(const char *input, char *output, size_t output_size) {
    size_t used = 0;
    if (output_size == 0) return;
    for (const unsigned char *p = (const unsigned char *)(input ? input : ""); *p && used + 2 < output_size; p++) {
        if (*p == '"' || *p == '\\') {
            output[used++] = '\\';
            output[used++] = (char)*p;
        } else if (*p >= 0x20) {
            output[used++] = (char)*p;
        }
    }
    output[used] = '\0';
}

static bool resolve_launch_user(const char *requested, int32_t *user_id_out) {
    OrbisUserServiceLoginUserIdList users;
    int32_t selected = -1;
    if (!user_id_out || !get_logged_in_users(&users)) return false;

    if (!requested || !requested[0] || strcmp(requested, "active") == 0) {
        if (sceUserServiceGetForegroundUser(&selected) != 0) return false;
    } else {
        char *end = NULL;
        long parsed = strtol(requested, &end, 10);
        if (!end || *end != '\0' || parsed <= 0 || parsed > INT32_MAX) return false;
        selected = (int32_t)parsed;
    }

    for (size_t i = 0; i < MAX_LOGIN_USERS; i++) {
        if (users.userId[i] == selected && users.userId[i] != USER_ID_INVALID) {
            *user_id_out = selected;
            return true;
        }
    }
    return false;
}

static void notify_ps4(const char *msg) {
    CaNotifyRequest req;
    memset(&req, 0, sizeof(req));
    snprintf(req.message, sizeof(req.message), "[Control de Apps] %s", msg);
    sceKernelSendNotificationRequest(0, &req, sizeof(req), 0);
}

/* Helper para extraer valores simples de JSON sin dependencias externas */
static bool json_extract_string(const char *json, const char *key, char *out, size_t out_size) {
    char pattern[128];
    snprintf(pattern, sizeof(pattern), "\"%s\"", key);
    const char *pos = strstr(json, pattern);
    if (!pos) return false;

    pos += strlen(pattern);
    while (*pos == ' ' || *pos == ':' || *pos == '\t') pos++;
    if (*pos != '\"') return false;
    pos++;

    size_t i = 0;
    while (*pos && *pos != '\"' && i + 1 < out_size) {
        if (*pos == '\\' && *(pos + 1)) pos++;
        out[i++] = *pos++;
    }
    out[i] = '\0';
    return true;
}

static bool valid_title_id(const char *title_id) {
    size_t len = title_id ? strlen(title_id) : 0;
    if (len < 4 || len > 16) return false;
    for (size_t i = 0; i < len; i++) {
        char c = title_id[i];
        if (!((c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '.' || c == '_' || c == '-')) return false;
    }
    return true;
}

static bool get_foreground_app(uint32_t *appid_out, char *title_id_out, size_t title_id_size) {
    if (!appid_out || !title_id_out || title_id_size < 17) return false;
    *appid_out = 0;
    title_id_out[0] = '\0';
    int32_t appid = sceSystemServiceGetAppIdOfBigApp();
    if (appid <= 0) return false;
    *appid_out = (uint32_t)appid;
    if (sceLncUtilGetAppTitleId((uint32_t)appid, title_id_out) != 0) {
        title_id_out[0] = '\0';
        return true;
    }
    title_id_out[title_id_size - 1] = '\0';
    if (!valid_title_id(title_id_out)) title_id_out[0] = '\0';
    return true;
}

static bool is_protected_title(const char *title_id) {
    static const char *protected_ids[] = {
        "NPXS20001", "NPXS20002", "CUSA00000", "SCESHELLUI", "SCESHELLCORE", "BINLOADER"
    };
    /* NPXS titles are system applications/services; never force-close them here. */
    if (title_id && strncmp(title_id, "NPXS", 4) == 0) return true;
    for (size_t i = 0; i < sizeof(protected_ids) / sizeof(protected_ids[0]); i++) {
        if (strcmp(title_id, protected_ids[i]) == 0) return true;
    }
    return false;
}

static void handle_client(int client_fd) {
    char buf[BUFFER_SIZE];
    memset(buf, 0, sizeof(buf));
    size_t received = 0;
    while (received < sizeof(buf) - 1) {
        ssize_t n = recv(client_fd, buf + received, sizeof(buf) - 1 - received, 0);
        if (n <= 0) break;
        received += (size_t)n;
        if (memchr(buf, '\n', received)) break;
    }
    if (received == 0) return;
    buf[received] = '\0';

    char action[64];
    memset(action, 0, sizeof(action));
    if (!json_extract_string(buf, "action", action, sizeof(action))) {
        /* Fallback para comandos de texto simple */
        if (strstr(buf, "ping")) strcpy(action, "ping");
        else if (strstr(buf, "status")) strcpy(action, "status");
        else if (strstr(buf, "users")) strcpy(action, "users");
        else if (strstr(buf, "close")) strcpy(action, "close_force");
        else if (strstr(buf, "launch")) strcpy(action, "launch");
        else strcpy(action, "unknown");
    }

    char response[BUFFER_SIZE];
    memset(response, 0, sizeof(response));

    if (strcmp(action, "ping") == 0) {
        snprintf(response, sizeof(response),
            "{\"status\":\"success\",\"service\":\"app_control_daemon\",\"port\":%d,\"version\":\"%s\"}\n",
            DAEMON_PORT, DAEMON_VERSION);
    }
    else if (strcmp(action, "status") == 0) {
        uint32_t appid = 0;
        char title_id[32] = {0};
        bool app_running = get_foreground_app(&appid, title_id, sizeof(title_id));
        snprintf(response, sizeof(response),
            "{\"status\":\"success\",\"action\":\"status\",\"app_running\":%s,\"app_id\":%u,\"title_id\":\"%s\",\"title_id_known\":%s,\"service\":\"app_control_daemon\",\"version\":\"%s\"}\n",
            app_running ? "true" : "false", appid, title_id, title_id[0] ? "true" : "false", DAEMON_VERSION);
    }
    else if (strcmp(action, "users") == 0) {
        OrbisUserServiceLoginUserIdList users;
        int32_t foreground_user = -1;
        if (!get_logged_in_users(&users) || sceUserServiceGetForegroundUser(&foreground_user) != 0) {
            snprintf(response, sizeof(response),
                "{\"status\":\"error\",\"action\":\"users\",\"message\":\"No se pudo consultar UserService.\"}\n");
        } else {
            size_t used = (size_t)snprintf(response, sizeof(response),
                "{\"status\":\"success\",\"action\":\"users\",\"active_user_id\":%d,\"users\":[",
                foreground_user);
            bool first = true;
            for (size_t i = 0; i < MAX_LOGIN_USERS && used < sizeof(response) - 2; i++) {
                int32_t user_id = users.userId[i];
                if (user_id == USER_ID_INVALID || user_id <= 0) continue;
                char name[128] = {0};
                char escaped[256] = {0};
                if (sceUserServiceGetUserName(user_id, name, sizeof(name)) != 0 || !name[0]) {
                    snprintf(name, sizeof(name), "Usuario %d", user_id);
                }
                json_escape_string(name, escaped, sizeof(escaped));
                int written = snprintf(response + used, sizeof(response) - used,
                    "%s{\"id\":%d,\"name\":\"%s\",\"active\":%s}",
                    first ? "" : ",", user_id, escaped,
                    user_id == foreground_user ? "true" : "false");
                if (written < 0 || (size_t)written >= sizeof(response) - used) break;
                used += (size_t)written;
                first = false;
            }
            if (used < sizeof(response) - 3) {
                snprintf(response + used, sizeof(response) - used, "]}\n");
            } else {
                snprintf(response, sizeof(response), "{\"status\":\"error\",\"action\":\"users\",\"message\":\"La respuesta de perfiles excedió el límite.\"}\n");
            }
        }
    }
    else if (strcmp(action, "close_graceful") == 0) {
        snprintf(response, sizeof(response),
            "{\"status\":\"not_supported\",\"action\":\"close_graceful\",\"message\":\"No verified graceful-close API is available.\"}\n");
    }
    else if (strcmp(action, "close_force") == 0) {
        char title_id[32];
        memset(title_id, 0, sizeof(title_id));
        json_extract_string(buf, "title_id", title_id, sizeof(title_id));

        char confirm_force[8] = {0};
        bool confirmed = json_extract_string(buf, "confirm_force", confirm_force, sizeof(confirm_force))
            && strcmp(confirm_force, "1") == 0;
        if (!confirmed || !valid_title_id(title_id) || is_protected_title(title_id)) {
            snprintf(response, sizeof(response),
                "{\"status\":\"error\",\"action\":\"close_force\",\"message\":\"Explicit confirmation, valid Title ID, and an unprotected target are required.\"}\n");
            send(client_fd, response, strlen(response), 0);
            return;
        }

        uint32_t verified_appid = 0;
        char active_title[32] = {0};
        if (!get_foreground_app(&verified_appid, active_title, sizeof(active_title))
            || !active_title[0] || strcmp(active_title, title_id) != 0) {
            snprintf(response, sizeof(response),
                "{\"status\":\"error\",\"action\":\"close_force\",\"code\":\"ACTIVE_TITLE_MISMATCH\",\"title_id\":\"%s\",\"active_title_id\":\"%s\",\"message\":\"The requested title is not the verified foreground app.\"}\n",
                title_id, active_title);
            send(client_fd, response, strlen(response), 0);
            return;
        }

        int appid = (int)verified_appid;
        int kill_res = -1;
        if (appid > 0) {
            /* how_to_kill=-1 requests the title's normal exit path in the PS4 ecosystem. */
            kill_res = sceSystemServiceKillApp(appid, -1, 0, 0);
        }

        if (kill_res < 0) {
            snprintf(response, sizeof(response),
                "{\"status\":\"error\",\"action\":\"close_force\",\"title_id\":\"%s\",\"app_id\":%d,\"result\":%d,\"message\":\"System close request failed.\"}\n",
                title_id, appid, kill_res);
            send(client_fd, response, strlen(response), 0);
            return;
        }

        usleep(750000);
        uint32_t after_appid = 0;
        char after_title[32] = {0};
        bool app_still_foreground = get_foreground_app(&after_appid, after_title, sizeof(after_title))
            && after_appid == verified_appid;
        if (app_still_foreground) {
            snprintf(response, sizeof(response),
                "{\"status\":\"accepted\",\"action\":\"close_force\",\"title_id\":\"%s\",\"app_id\":%d,\"result\":%d,\"message\":\"The close request was accepted, but the title remains in the foreground.\"}\n",
                title_id, appid, kill_res);
            send(client_fd, response, strlen(response), 0);
            return;
        }

        /* Enviar confirmación visual en pantalla de la consola */
        char toast[128];
        snprintf(toast, sizeof(toast), "PS4 ya no muestra %s en primer plano", title_id);
        notify_ps4(toast);

        snprintf(response, sizeof(response),
            "{\"status\":\"success\",\"action\":\"close_force\",\"title_id\":\"%s\",\"app_id\":%d,\"result\":%d,\"state\":\"no_longer_foreground\",\"message\":\"The title is no longer in the foreground.\"}\n",
            title_id, appid, kill_res);
    }
    else if (strcmp(action, "launch") == 0) {
        char title_id[32];
        memset(title_id, 0, sizeof(title_id));
        json_extract_string(buf, "title_id", title_id, sizeof(title_id));

        if (!valid_title_id(title_id)) {
            snprintf(response, sizeof(response),
                "{\"status\":\"error\",\"action\":\"launch\",\"message\":\"Invalid or missing Title ID.\"}\n");
        } else {
            char requested_user[32] = {0};
            int32_t launch_user_id = -1;
            json_extract_string(buf, "user_id", requested_user, sizeof(requested_user));
            if (!resolve_launch_user(requested_user, &launch_user_id)) {
                snprintf(response, sizeof(response),
                    "{\"status\":\"error\",\"action\":\"launch\",\"code\":\"INVALID_OR_INACTIVE_USER\",\"message\":\"No se pudo validar el perfil seleccionado o el usuario activo. No se lanzó la app.\"}\n");
                send(client_fd, response, strlen(response), 0);
                return;
            }

            LncAppParam param;
            memset(&param, 0, sizeof(param));
            param.size = sizeof(param);
            param.user_id = (uint32_t)launch_user_id;
            param.app_opt = 0;
            param.crash_report = 0;
            param.LaunchAppCheck_flag = 0;

            int launch_res = sceLncUtilLaunchApp(title_id, NULL, &param);

            if (launch_res < 0) {
                snprintf(response, sizeof(response),
                    "{\"status\":\"error\",\"action\":\"launch\",\"title_id\":\"%s\",\"result\":%d,\"message\":\"System launch request failed.\"}\n",
                    title_id, launch_res);
            } else {
                usleep(1200000);
                uint32_t running_appid = 0;
                char running_title[32] = {0};
                bool is_running = get_foreground_app(&running_appid, running_title, sizeof(running_title));
                if (is_running && strcmp(running_title, title_id) == 0) {
                    char toast[128];
                    snprintf(toast, sizeof(toast), "Aplicacion %s abierta", title_id);
                    notify_ps4(toast);
                    snprintf(response, sizeof(response),
                        "{\"status\":\"success\",\"action\":\"launch\",\"title_id\":\"%s\",\"user_id\":%d,\"app_id\":%u,\"result\":%d,\"state\":\"foreground\",\"message\":\"The requested title is in the foreground.\"}\n",
                        title_id, launch_user_id, running_appid, launch_res);
                } else {
                    snprintf(response, sizeof(response),
                        "{\"status\":\"accepted\",\"action\":\"launch\",\"title_id\":\"%s\",\"user_id\":%d,\"result\":%d,\"message\":\"The launch request was accepted; foreground state is not confirmed.\"}\n",
                        title_id, launch_user_id, launch_res);
                }
            }
        }
    }
    else {
        snprintf(response, sizeof(response),
            "{\"status\":\"error\",\"message\":\"Accion desconocida: %s\"}\n", action);
    }

    send(client_fd, response, strlen(response), 0);
}

int main(void) {
    /* Ignorar SIGPIPE para evitar cierre si el cliente desconecta abruptamente */
    signal(SIGPIPE, SIG_IGN);

    int32_t netctl_result = sceNetCtlInit();
    if (netctl_result != 0) {
        char msg[128];
        snprintf(msg, sizeof(msg), "No se pudo inicializar NetCtl (%d).", netctl_result);
        notify_ps4(msg);
        return 10;
    }

    int32_t net_result = sceNetInit();
    if (net_result != 0) {
        char msg[128];
        snprintf(msg, sizeof(msg), "No se pudo inicializar la red (%d).", net_result);
        notify_ps4(msg);
        return 11;
    }

    int32_t net_pool = sceNetPoolCreate("ghm_app_control", 1024 * 1024, 0);
    if (net_pool < 0) {
        char msg[128];
        snprintf(msg, sizeof(msg), "No se pudo crear el pool de red (%d).", net_pool);
        notify_ps4(msg);
        return 12;
    }

    int srv_fd = socket(AF_INET, SOCK_STREAM, 0);
    if (srv_fd < 0) {
        notify_ps4("La red inicio, pero no se pudo crear el socket 9091.");
        return 1;
    }

    int opt = 1;
    setsockopt(srv_fd, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));

    struct sockaddr_in srv_addr;
    memset(&srv_addr, 0, sizeof(srv_addr));
    srv_addr.sin_family = AF_INET;
    srv_addr.sin_port = htons(DAEMON_PORT);
    srv_addr.sin_addr.s_addr = INADDR_ANY;

    if (bind(srv_fd, (struct sockaddr *)&srv_addr, sizeof(srv_addr)) < 0) {
        close(srv_fd);
        /* Puerto ocupado: otra instancia ya esta corriendo */
        notify_ps4("No se pudo reservar 9091; comprueba si ya hay otra instancia.");
        return 13;
    }

    if (listen(srv_fd, 8) < 0) {
        close(srv_fd);
        notify_ps4("El socket existe, pero listen() fallo en 9091.");
        return 2;
    }

    /* Notificación de inicio exitoso en la consola */
    notify_ps4("Servicio de Control de Apps activo (Puerto 9091)");

    while (1) {
        struct sockaddr_in client_addr;
        socklen_t client_len = sizeof(client_addr);
        int client_fd = accept(srv_fd, (struct sockaddr *)&client_addr, &client_len);
        if (client_fd < 0) {
            usleep(100000);
            continue;
        }

        /* Timeout de socket de 4 segundos para lecturas/escrituras de clientes */
        struct timeval tv;
        tv.tv_sec = 4;
        tv.tv_usec = 0;
        setsockopt(client_fd, SOL_SOCKET, SO_RCVTIMEO, (const char *)&tv, sizeof(tv));
        setsockopt(client_fd, SOL_SOCKET, SO_SNDTIMEO, (const char *)&tv, sizeof(tv));

        handle_client(client_fd);
        close(client_fd);
    }

    close(srv_fd);
    return 0;
}
