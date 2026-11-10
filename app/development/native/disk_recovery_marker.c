/*
 * Experimental EX4100 recovery marker helper — NOT approved for deployment.
 * Fixed private NAS directory; no disk, mount, cryptsetup or clear operations.
 * Build and validate off-device before considering packaging.
 */
#define _POSIX_C_SOURCE 200809L
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>

#ifndef PRIVATE_DIR
#define PRIVATE_DIR "/mnt/HD/HD_a2/Nas_Prog/_EncDiskManager"
#endif
#define MARKER "disk-operation-recovery-required"

static int fail(const char *message) {
    fprintf(stderr, "recovery marker: %s: %s\n", message, strerror(errno));
    return 1;
}

int main(int argc, char **argv) {
    if (argc != 2 || strcmp(argv[1], "mark") != 0) {
        fprintf(stderr, "usage: disk_recovery_marker mark\n");
        return 2;
    }
    if (geteuid() != 0 || getuid() != 0) {
        fprintf(stderr, "recovery marker: root required\n");
        return 1;
    }

    /* Open the fixed private directory without following its final symlink.
     * Ancestor-path trust and shared-lock ownership remain integration gates.
     */
    int dirfd = open(PRIVATE_DIR, O_RDONLY | O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC);
    if (dirfd < 0) return fail("open private directory");
    struct stat ds;
    if (fstat(dirfd, &ds) != 0 || !S_ISDIR(ds.st_mode)
        || ds.st_uid != 0 || (ds.st_mode & 0777) != 0700) {
        fprintf(stderr, "recovery marker: unsafe private directory\n");
        close(dirfd);
        return 1;
    }

    /* Existing marker is an unresolved state, not permission to overwrite. */
    int fd = openat(dirfd, MARKER,
        O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW | O_CLOEXEC, 0600);
    if (fd < 0) {
        int saved = errno;
        close(dirfd);
        errno = saved;
        return fail("exclusive marker creation");
    }
    const char record[] = "recovery-required\n";
    size_t written = 0;
    while (written < sizeof(record) - 1) {
        ssize_t n = write(fd, record + written, sizeof(record) - 1 - written);
        if (n < 0 && errno == EINTR) continue;
        if (n <= 0) {
            fprintf(stderr, "recovery marker: incomplete write; marker retained\n");
            close(fd);
            close(dirfd);
            return 1;
        }
        written += (size_t)n;
    }
    struct stat fs;
    if (fstat(fd, &fs) != 0 || !S_ISREG(fs.st_mode)
        || fs.st_uid != 0 || (fs.st_mode & 0777) != 0600) {
        fprintf(stderr, "recovery marker: unsafe marker metadata\n");
        close(fd);
        close(dirfd);
        return 1;
    }
    if (fsync(fd) != 0) {
        int saved = errno;
        close(fd);
        close(dirfd);
        errno = saved;
        return fail("fsync marker");
    }
    if (close(fd) != 0) {
        close(dirfd);
        return fail("close marker");
    }
    if (fsync(dirfd) != 0) {
        int saved = errno;
        close(dirfd);
        errno = saved;
        return fail("fsync private directory");
    }
    if (close(dirfd) != 0) return fail("close private directory");
    return 0;
}
