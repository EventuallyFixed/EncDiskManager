#!/usr/bin/env python3
"""Bounded isolated test: coordinator exits while harmless child remains alive.

No signals, NAS paths, privileged disk operations, or unbounded orphan process.
Child deliberately does NOT inherit the lock FD, to test the worst-case
authorization gap where the kernel lock is released after parent exit.
"""
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

CHILD = r"""
import os,sys,time
from pathlib import Path
root=Path(sys.argv[1])
(root/'child-ready').write_text('ready')
time.sleep(3)
(root/'child-finished').write_text('finished')
"""
COORDINATOR = r"""
import fcntl,os,subprocess,sys
from pathlib import Path
root=Path(sys.argv[1]); lock=root/'lock'; pending=root/'pending'
fd=os.open(lock,os.O_CREAT|os.O_RDWR,0o600)
fcntl.flock(fd,fcntl.LOCK_EX)
m=os.open(pending,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
os.write(m,b'pending\n');os.fsync(m);os.close(m)
d=os.open(root,os.O_RDONLY|os.O_DIRECTORY);os.fsync(d);os.close(d)
child=subprocess.Popen([sys.executable,'-c',sys.argv[2],str(root)],
                       stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL,close_fds=True,
                       start_new_session=True)
(root/'child-pid').write_text(str(child.pid))
os._exit(17)
"""
RESTART = r"""
import fcntl,os,sys
from pathlib import Path
root=Path(sys.argv[1])
fd=os.open(root/'lock',os.O_RDWR)
fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
if (root/'pending').exists(): sys.exit(12)
sys.exit(0)
"""
with tempfile.TemporaryDirectory(prefix="edm-surviving-child-") as tmp:
    root=Path(tmp)
    parent=subprocess.run([sys.executable,'-c',COORDINATOR,str(root),CHILD],
                          timeout=10)
    assert parent.returncode==17,parent.returncode
    deadline=time.monotonic()+2
    while not (root/'child-ready').exists() and time.monotonic()<deadline:
        time.sleep(0.025)
    assert (root/'child-ready').exists(),"child did not start"
    assert not (root/'child-finished').exists(),"child exited before restart"
    restarted=subprocess.run([sys.executable,'-c',RESTART,str(root)],
                             timeout=5)
    assert restarted.returncode==12, restarted.returncode
    assert (root/'pending').read_bytes()==b'pending\n'
    deadline=time.monotonic()+6
    while not (root/'child-finished').exists() and time.monotonic()<deadline:
        time.sleep(0.05)
    assert (root/'child-finished').exists(),"bounded child did not finish"
    restarted=subprocess.run([sys.executable,'-c',RESTART,str(root)],
                             timeout=5)
    assert restarted.returncode==12
    print("PASS: pending gate blocks restart during and after surviving child")
    print("LIMIT: no real disk child, power loss, or NAS execution")
