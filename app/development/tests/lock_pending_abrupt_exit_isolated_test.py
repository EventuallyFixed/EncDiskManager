#!/usr/bin/env python3
"""Isolated abrupt coordinator exit and restart gate, temporary files only.

os._exit simulates abrupt process termination after durable pending write.
It does not model power loss, a surviving disk-operation child, or NAS behavior.
"""
import os
from pathlib import Path
import subprocess
import sys
import tempfile

SCRIPT = r"""
import fcntl,os,sys
from pathlib import Path
root=Path(sys.argv[1]); mode=sys.argv[2]
lock=root/'disk-operations.lock'; pending=root/'pending'
fd=os.open(lock,os.O_RDWR|os.O_CREAT,0o600)
fcntl.flock(fd,fcntl.LOCK_EX)
if pending.exists():
    print('BLOCKED: unresolved pending record',flush=True)
    sys.exit(12)
if mode=='restart':
    print('AUTHORIZED: no pending record',flush=True)
    sys.exit(0)
marker=os.open(pending,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
os.write(marker,b'pending\n')
os.fsync(marker)
os.close(marker)
dirfd=os.open(root,os.O_RDONLY|os.O_DIRECTORY)
os.fsync(dirfd)
os.close(dirfd)
print('PENDING: abrupt exit',flush=True)
os._exit(17)
"""

with tempfile.TemporaryDirectory(prefix="edm-abrupt-exit-") as tmp:
    root=Path(tmp)
    def run(mode):
        return subprocess.run([sys.executable,"-c",SCRIPT,str(root),mode],
                              capture_output=True,text=True,timeout=10)
    crashed=run("abrupt")
    assert crashed.returncode==17,(crashed.stdout,crashed.stderr)
    assert (root/"pending").read_bytes()==b"pending\n"
    for _ in range(3):
        restarted=run("restart")
        assert restarted.returncode==12,(restarted.stdout,restarted.stderr)
        assert "BLOCKED" in restarted.stdout
    assert (root/"pending").read_bytes()==b"pending\n"
    print("PASS: abrupt coordinator exit leaves restart authorization blocked")
    print("LIMIT: no surviving child, actual power failure, or NAS execution")
