/*
 * Isolated inherited flock FD probe. No NAS paths or disk operations.
 * FD identity is NOT proof that the caller owns an exclusive flock.
 */
#define _POSIX_C_SOURCE 200809L
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/stat.h>
#include <unistd.h>

int main(int argc, char **argv) {
    if (argc != 3) {
        fprintf(stderr, "usage: lock_fd_probe_isolated FD EXPECTED_FILE\n");
        return 2;
    }
    char *end = NULL;
    errno = 0;
    long value = strtol(argv[1], &end, 10);
    if (errno || !end || *end || end == argv[1] || value < 0 || value > 1048576) {
        fprintf(stderr, "invalid FD\n");
        return 1;
    }
    int fd = (int)value;
    struct stat actual, expected;
    if (fstat(fd, &actual) || !S_ISREG(actual.st_mode)) {
        fprintf(stderr, "missing or nonregular FD\n");
        return 1;
    }
    if (lstat(argv[2], &expected) || !S_ISREG(expected.st_mode)
        || actual.st_dev != expected.st_dev || actual.st_ino != expected.st_ino) {
        fprintf(stderr, "unexpected lock inode or symlink\n");
        return 1;
    }
    puts("PASS: inherited FD identifies expected regular inode");
    puts("NOTE: lock ownership is NOT established by this probe");
    return 0;
}
