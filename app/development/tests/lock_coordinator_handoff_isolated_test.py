#!/usr/bin/env python3
"""Isolated coordinator/child handoff experiment; temporary files only.

This demonstrates serialization and a persistent pending-record gate.
It is not a production authorization or crash-durability implementation.
"""
import fcntl
import os
from pathlib import Path
import subprocess
import sys
import tempfile

CHILD = """
import os,sys
fd=int(sys.argv[1]); expected=sys.argv[2]
a=os.fstat(fd); b=os.stat(expected,follow_symlinks=False)
if not (a.st_dev==b.st_dev and a.st_ino==b.st_ino):
    sys.exit(13)
print('child verified inherited inode',flush=True)
"""

def contend(path):
    code = """import os,fcntl,sys
fd=os.open(sys.argv[1],os.O_RDWR)
try: fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError: sys.exit(10)
else: fcntl.flock(fd,fcntl.LOCK_UN);sys.exit(0)
"""
    return subprocess.run([sys.executable,"-c",code,str(path)]).returncode

with tempfile.TemporaryDirectory(prefix="edm-coordinator-") as tmp:
    directory=Path(tmp)
    lock=directory/"disk-operations.lock"
    pending=directory/"pending"
    lock.touch(mode=0o600)
    fd=os.open(lock,os.O_RDWR)
    try:
        fcntl.flock(fd,fcntl.LOCK_EX)
        assert contend(lock)==10
        assert not pending.exists()
        # Simulated write-ahead record. Sync both file and directory.
        markerfd=os.open(pending,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        try:
            os.write(markerfd,b"pending\n")
            os.fsync(markerfd)
        finally:
            os.close(markerfd)
        dirfd=os.open(directory,os.O_RDONLY|os.O_DIRECTORY)
        try: os.fsync(dirfd)
        finally: os.close(dirfd)
        child=subprocess.run([sys.executable,"-c",CHILD,str(fd),str(lock)],
                             pass_fds=(fd,),capture_output=True,text=True)
        assert child.returncode==0,(child.stdout,child.stderr)
        assert contend(lock)==10
        # A failed child handoff must not clear the durable pending gate.
        failed=subprocess.run([sys.executable,"-c",CHILD,str(fd),str(directory/"wrong")],
                              pass_fds=(fd,),capture_output=True,text=True)
        assert failed.returncode!=0
        assert pending.read_text()=="pending\n"
        fcntl.flock(fd,fcntl.LOCK_UN)
        assert contend(lock)==0
        assert pending.exists(), "pending record must survive lock release"
        print("PASS: isolated coordinator lock handoff and pending-state gate")
        print("LIMIT: no parent-crash or real disk-operation authorization tested")
    finally:
        os.close(fd)
