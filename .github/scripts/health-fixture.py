"""Run only in the disposable release container, with the supplied test certificate."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json
import ssl
import subprocess
import sys

root = Path(sys.argv[1])
ip = '11.254.233.2'


class Origin(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path != '/generate_204':
            self.send_error(404)
            return
        self.send_response(204 if not (root / 'origin-down').exists() else 503)
        self.end_headers()

    do_HEAD = do_GET

    def log_message(self, *_):
        pass


server = ThreadingHTTPServer((ip, 443), Origin)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(root / 'server.crt', root / 'server.key')
server.socket = context.wrap_socket(server.socket, server_side=True)
tls = {'enabled': True, 'certificate_path': str(root / 'server.crt'), 'key_path': str(root / 'server.key')}
inbounds = []
for protocol, port in [('vless', 19001), ('trojan', 19002), ('hysteria2', 19003), ('anytls', 19004)]:
    user = {'uuid': '75fc28e4-e2fa-4584-9685-f8f1651a6336'} if protocol == 'vless' else {'password': 'health-fixture-secret'}
    inbounds.append({'type': protocol, 'tag': protocol, 'listen': ip, 'listen_port': port, 'users': [user], 'tls': tls})
config = {'log': {'disabled': True}, 'inbounds': inbounds,
          'outbounds': [{'type': 'direct', 'tag': 'origin'}],
          'route': {'rules': [{'domain': ['www.gstatic.com', 'cp.cloudflare.com'], 'action': 'route',
                               'outbound': 'origin', 'override_address': ip}, {'action': 'reject'}]}}
config_file = root / 'servers.json'


def start_peer():
    config_file.write_text(json.dumps(config))
    return subprocess.Popen(['/usr/local/bin/sing-box', 'run', '-c', config_file])


peer = start_peer()
try:
    server.timeout = 0.5
    while peer.poll() is None:
        server.handle_request()
        if (root / 'restore-account').exists() and not (root / 'restored').exists():
            peer.terminate()
            peer.wait(timeout=5)
            inbounds[0]['users'].append({'name': 'restored', 'uuid': '06aa6fde-4c0f-42ed-8efb-35c1772a8402'})
            peer = start_peer()
            (root / 'restored').touch()
    raise SystemExit('Synthetic protocol server exited early')
finally:
    peer.terminate()
    peer.wait(timeout=5)
    server.server_close()
