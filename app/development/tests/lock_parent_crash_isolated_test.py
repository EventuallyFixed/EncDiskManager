#!/usr/bin/env python3
"""Host-only isolated flock parent-crash test; no devices or NAS paths."""
import fcntl
import os
import pathlib
import select
import signal
import subprocess
import sys
import tempfile
import time

CHILD = """
import os,sys,time
fd=int(sys.argv[1])
os.fstat(fd)
print("CHILD_READY",flush=True)
time.sleep(30)
"""
PARENT = """
import fcntl,os,subprocess,sys
path=sys.argv[1]
fd=os.open(path,os.O_RDWR)
fcntl.flock(fd,fcntl.LOCK_EX)
child=subprocess.Popen([sys.executable,"-c",sys.argv[2],str(fd)],
    pass_fds=(fd,),stdout=subprocess.PIPE,text=True,start_new_session=True)
if child.stdout.readline().strip()!="CHILD_READY": sys.exit(3)
print(child.pid,flush=True)
child.stdout.close()
child.wait()
"""
PROBE = """
import fcntl,os,sys
fd=os.open(sys.argv[1],os.O_RDWR)
try:
 fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError: sys.exit(9)
else: sys.exit(0)
finally: os.close(fd)
"""
def probe(path):
    return subprocess.run([sys.executable,"-c",PROBE,str(path)],check=False).returncode

with tempfile.TemporaryDirectory(prefix="edm-parent-crash-") as tmp:
    path = pathlib.Path(tmp) / "disk-operations.lock"
    path.touch(mode=0o600)
    parent = subprocess.Popen(
        [sys.executable,"-c",PARENT,str(path),CHILD],
        stdout=subprocess.PIPE,text=True,
    )
    child_pid = None
    child_starttime = None
    def child_matches():
        if child_pid is None or child_starttime is None:
            return False
        try:
            with open("/proc/%d/stat" % child_pid, encoding="ascii") as stream:
                stat = stream.read()
            fields = stat.rsplit(")", 1)[1].strip().split()
            # fields[3] is session ID (field 6), fields[19] is
            # kernel process starttime (field 22).
            return (int(fields[3]) == child_pid
                    and fields[19] == child_starttime
                    and fields[0] != "Z")
        except (FileNotFoundError, ProcessLookupError, IndexError, ValueError):
            return False
    try:
        ready, _, _ = select.select([parent.stdout], [], [], 10)
        if not ready:
            raise TimeoutError("parent did not report child readiness")
        line = parent.stdout.readline().strip()
        child_pid = int(line)
        with open("/proc/%d/stat" % child_pid, encoding="ascii") as stream:
            child_starttime = stream.read().rsplit(")", 1)[1].strip().split()[19]
        if not child_matches():
            raise AssertionError("child identity not established")
        assert probe(path) == 9, "lock not held before parent termination"
        parent.kill()
        parent.wait(timeout=10)
        assert probe(path) == 9, "parent death released child-held lock"
        print("PASS: child-held lock survives parent SIGKILL")
        # Verify session and original kernel starttime before signaling.
        if not child_matches():
            raise AssertionError("orphan child identity cannot be verified")
        os.kill(child_pid, signal.SIGKILL)
        # A short bounded retry accommodates kernel process cleanup.
        for _ in range(50):
            if probe(path) == 0:
                print("PASS: lock released after child SIGKILL")
                break
            time.sleep(0.1)
        else:
            raise AssertionError("lock still held after child termination")
    finally:
        if parent.poll() is None:
            parent.kill()
        parent.wait(timeout=10)
        parent.stdout.close()
        if child_pid is not None:
            if child_matches():
                try:
                    os.kill(child_pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
print("NOTE: isolated Linux CI test only; EX4100 parent-crash behavior unverified")
