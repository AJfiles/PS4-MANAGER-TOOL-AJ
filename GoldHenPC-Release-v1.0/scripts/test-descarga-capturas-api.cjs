const assert = require('node:assert/strict');

// Requiere el servidor local del paquete PC escuchando en 127.0.0.1:8080.
const childProcess = require('node:child_process');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');

const fixtureFiles = new Map([
    ['/user/av_contents/photo/CUSA00001/a.jpg', Buffer.from('capture-fixture-ps4-manager-tool')],
    ['/user/av_contents/photo/CUSA00002/b.png', Buffer.from('second-capture-fixture')]
]);
let cwd = '/';
const commandLog = [];
let pendingTransfer = null;
let pendingSocket = null;
let passiveServer = null;

function normalizedPath(value) {
    const resolved = path.posix.resolve(cwd, value);
    return resolved.startsWith('/') ? resolved : '/' + resolved;
}
function serveTransfer() {
    if (!pendingTransfer || !pendingSocket) return;
    const transfer = pendingTransfer;
    const dataSocket = pendingSocket;
    pendingTransfer = null;
    pendingSocket = null;
    transfer(dataSocket);
}

const controlServer = net.createServer(socket => {
    cwd = '/';
    let buffer = '';
    // curl puede cerrar el canal de datos al abortar una transferencia fallida;
    // el fixture conserva ese fallo para las aserciones HTTP sin lanzar un
    // evento 'error' no manejado que mate todo el proceso Node.
    socket.on('error', error => {
        if (error.code !== 'ECONNRESET') console.error(`FTP control socket: ${error.message}`);
    });
    const reply = text => socket.write(text + '\r\n');
    reply('220 Local FTP fixture ready');
    socket.on('data', chunk => {
        buffer += chunk.toString('utf8');
        const lines = buffer.split(/\r?\n/);
        buffer = lines.pop() || '';
        for (const line of lines) if (line) void handleCommand(line, socket, reply);
    });

    async function handleCommand(line, controlSocket, respond) {
        commandLog.push(line);
        const [command, ...rest] = line.split(' ');
        const arg = rest.join(' ');
        switch (command.toUpperCase()) {
            case 'USER': respond('331 Password required'); break;
            case 'PASS': respond('230 Logged in'); break;
            case 'SYST': respond('215 UNIX Type: L8'); break;
            case 'FEAT': respond('211 End'); break;
            case 'OPTS': respond('200 Option accepted'); break;
            case 'TYPE': respond('200 Type set'); break;
            case 'PWD': respond(`257 "${cwd}"`); break;
            case 'CWD': cwd = normalizedPath(arg); respond('250 Directory changed'); break;
            case 'CDUP': cwd = path.posix.dirname(cwd); respond('200 Directory changed'); break;
            case 'SIZE': {
                const file = normalizedPath(arg);
                respond(fixtureFiles.has(file) ? `213 ${fixtureFiles.get(file).length}` : '550 File unavailable');
                break;
            }
            case 'PASV': {
                if (passiveServer) { try { passiveServer.close(); } catch {} }
                passiveServer = net.createServer(dataSocket => {
                    dataSocket.on('error', error => {
                        if (error.code !== 'ECONNRESET') console.error(`FTP data socket: ${error.message}`);
                    });
                    pendingSocket = dataSocket;
                    serveTransfer();
                });
                passiveServer.listen(0, '127.0.0.1', () => {
                    const port = passiveServer.address().port;
                    respond(`227 Entering Passive Mode (127,0,0,1,${Math.floor(port / 256)},${port % 256})`);
                });
                break;
            }
            case 'LIST':
            case 'NLST': {
                respond('150 Opening data connection');
                pendingTransfer = dataSocket => {
                    const listing = [...fixtureFiles.entries()]
                        .filter(([file]) => path.posix.dirname(file) === cwd)
                        .map(([file, data]) => `-rw-r--r-- 1 owner group ${data.length} Jan 01 00:00 ${path.posix.basename(file)}\r\n`)
                        .join('');
                    dataSocket.end(listing);
                    dataSocket.once('close', () => respond('226 Transfer complete'));
                };
                serveTransfer();
                break;
            }
            case 'DELE': {
                const file = normalizedPath(arg);
                if (fixtureFiles.delete(file)) respond('250 File deleted');
                else respond('550 File unavailable');
                break;
            }
            case 'RETR': {
                const file = normalizedPath(arg);
                if (!fixtureFiles.has(file)) { respond('550 File unavailable'); break; }
                respond('150 Opening data connection');
                pendingTransfer = dataSocket => {
                    dataSocket.end(fixtureFiles.get(file));
                    dataSocket.once('close', () => respond('226 Transfer complete'));
                };
                serveTransfer();
                break;
            }
            case 'NOOP': respond('200 OK'); break;
            case 'QUIT': respond('221 Bye'); controlSocket.end(); break;
            default: respond('200 Command accepted');
        }
    }
});

(async () => {
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ps4-capture-api-test-'));
    try {
        await new Promise(resolve => controlServer.listen(0, '127.0.0.1', resolve));
        const ftpPort = controlServer.address().port;
        const blockedForm = new FormData();
        blockedForm.append('action', 'delete_capture');
        blockedForm.append('host_ip', '127.0.0.1');
        blockedForm.append('port', String(ftpPort));
        blockedForm.append('remote_path', '/user/av_contents/photo/CUSA00001/a.jpg');
        const deleBeforeCrossOrigin = commandLog.filter(line => line.startsWith('DELE ')).length;
        const blockedResponse = await fetch('http://127.0.0.1:8080/api/ps4_screenshots_api.php', {
            method: 'POST', body: blockedForm, headers: { Origin: 'https://attacker.example' }
        });
        assert.equal(blockedResponse.status, 403, 'El router debe rechazar un POST de otro origen antes de acceder al FTP.');
        assert.equal(commandLog.filter(line => line.startsWith('DELE ')).length, deleBeforeCrossOrigin);

        const form = new FormData();
        form.append('action', 'download_selected');
        form.append('host_ip', '127.0.0.1');
        form.append('port', String(ftpPort));
        for (const file of fixtureFiles.keys()) form.append('remote_paths[]', file);
        const response = await fetch('http://127.0.0.1:8080/api/ps4_screenshots_api.php', { method: 'POST', body: form, headers: { Origin: 'http://127.0.0.1:8080' } });
        assert.equal(response.status, 200);
        const responseType = response.headers.get('content-type') || '';
        if (!/application\/zip/i.test(responseType)) throw new Error(`Expected ZIP response, got ${responseType}: ${await response.text()} FTP=${commandLog.join('|')}`);
        const archivePath = path.join(tempDir, 'captures.zip');
        fs.writeFileSync(archivePath, Buffer.from(await response.arrayBuffer()));
        assert.ok(fs.statSync(archivePath).size > [...fixtureFiles.values()].reduce((sum, file) => sum + file.length, 0));

        const php = path.join(__dirname, '..', 'GoldHenPC-Release-v1.0', 'runtime', 'php.exe');
        const phpCheck = '$z=new ZipArchive();if($z->open($argv[1])!==true)exit(1);$a=$z->getFromName("CUSA00001/a.jpg");$b=$z->getFromName("CUSA00002/b.png");$n=$z->numFiles;$z->close();if($a!=="capture-fixture-ps4-manager-tool"||$b!=="second-capture-fixture"||$n!==2)exit(2);echo "ZIP_FIXTURE_OK";';
        const checked = childProcess.execFileSync(php, ['-r', phpCheck, '--', archivePath], { encoding: 'utf8' });
        assert.match(checked, /ZIP_FIXTURE_OK/);

        const deleteForm = new FormData();
        deleteForm.append('action', 'delete_capture');
        deleteForm.append('host_ip', '127.0.0.1');
        deleteForm.append('port', String(ftpPort));
        deleteForm.append('remote_path', '/user/av_contents/photo/CUSA00001/a.jpg');
        const deleteResponse = await fetch('http://127.0.0.1:8080/api/ps4_screenshots_api.php', { method: 'POST', body: deleteForm, headers: { Origin: 'http://127.0.0.1:8080' } });
        const deleteResult = await deleteResponse.json();
        assert.equal(deleteResponse.status, 200);
        assert.equal(deleteResult.status, 'success', `Delete failed: ${JSON.stringify(deleteResult)} FTP=${commandLog.join('|')}`);
        assert.equal(fixtureFiles.has('/user/av_contents/photo/CUSA00001/a.jpg'), false);
        assert.ok(commandLog.includes('DELE /user/av_contents/photo/CUSA00001/a.jpg'), `Expected unquoted FTP DELE command, got: ${commandLog.join('|')}`);

        const deleteCommandsBeforeUnsafePath = commandLog.filter(line => line.startsWith('DELE ')).length;
        const unsafeDelete = new FormData();
        unsafeDelete.append('action', 'delete_capture');
        unsafeDelete.append('host_ip', '127.0.0.1');
        unsafeDelete.append('port', String(ftpPort));
        unsafeDelete.append('remote_path', '/user/av_contents/photo/../other/evil.jpg');
        const unsafeResponse = await fetch('http://127.0.0.1:8080/api/ps4_screenshots_api.php', { method: 'POST', body: unsafeDelete, headers: { Origin: 'http://127.0.0.1:8080' } });
        const unsafeResult = await unsafeResponse.json();
        assert.equal(unsafeResult.status, 'error');
        assert.equal(commandLog.filter(line => line.startsWith('DELE ')).length, deleteCommandsBeforeUnsafePath);
        assert.equal(fixtureFiles.has('/other/evil.jpg'), false);
        console.log('CAPTURE_ZIP_API_FIXTURE_OK');
    } finally {
        controlServer.close();
        if (passiveServer) { try { passiveServer.close(); } catch {} }
        fs.rmSync(tempDir, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
