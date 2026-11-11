#!/usr/bin/env python3
"""Fail-closed write-ahead record failure injection, temp files only.

Models failures at create/write/file fsync/close/directory fsync/launch.
A child may start only after all durability steps report success.
This does not simulate actual power loss or prove storage durability.
"""
import os
from pathlib import Path
import tempfile

STEPS=("create","write","file_fsync","file_close","directory_fsync","launch")
def scenario(root, fail_at=None):
    pending=root/"pending"
    launched=[]
    fd=None
    dirfd=None
    try:
        if fail_at=="create": raise OSError("injected create")
        fd=os.open(pending,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        if fail_at=="write": raise OSError("injected write")
        record=b"pending\n"
        assert os.write(fd,record)==len(record)
        if fail_at=="file_fsync": raise OSError("injected file fsync")
        os.fsync(fd)
        if fail_at=="file_close": raise OSError("injected file close")
        os.close(fd)
        fd=None
        dirfd=os.open(root,os.O_RDONLY|os.O_DIRECTORY)
        if fail_at=="directory_fsync": raise OSError("injected directory fsync")
        os.fsync(dirfd)
        os.close(dirfd)
        dirfd=None
        if fail_at=="launch": raise OSError("injected launch")
        launched.append(True)  # harmless stand-in for child launch
        return True,launched
    except OSError:
        return False,launched
    finally:
        if fd is not None: os.close(fd)
        if dirfd is not None: os.close(dirfd)

with tempfile.TemporaryDirectory(prefix="edm-pending-failure-") as temp:
    base=Path(temp)
    for step in STEPS:
        directory=base/step
        directory.mkdir()
        ok,launched=scenario(directory,step)
        assert not ok and not launched,step
        if step!="create":
            assert (directory/"pending").exists(),step
    success=base/"success"
    success.mkdir()
    ok,launched=scenario(success)
    assert ok and launched
    # An existing unresolved marker is never overwritten or cleared.
    again,second=scenario(success)
    assert not again and not second
    assert (success/"pending").read_bytes()==b"pending\n"
    print("PASS: injected failures prevent child launch and retain pending state")
    print("LIMIT: simulated failures do not prove real power-loss durability")
