#!/usr/bin/env python3
"""Atlas-only certificate-copy hook; never issues certificates or edits nginx."""
from pathlib import Path
import grp,os,subprocess
source=Path('/etc/nginx/ssl-certificates');target=Path('/etc/narzan-calls')
subprocess.run(['openssl','x509','-in',str(source/'narzan.com.crt'),'-checkend','86400','-noout'],check=True,stdout=subprocess.DEVNULL)
changed=False
for old,new in [('narzan.com.crt','fullchain.pem'),('narzan.com.key','privkey.pem')]:
 data=(source/old).read_bytes();dst=target/new
 if not dst.exists() or dst.read_bytes()!=data:
  pending=dst.with_suffix('.new');pending.write_bytes(data);os.chmod(pending,0o640);os.chown(pending,0,grp.getgrnam('turnserver').gr_gid);pending.replace(dst);changed=True
if changed:subprocess.run(['systemctl','restart','narzan-turn.service'],check=True)
