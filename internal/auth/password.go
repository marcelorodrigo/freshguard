package auth

import (
	"crypto/rand"
	"crypto/subtle"
	"encoding/base64"
	"errors"
	"fmt"
	"strings"

	"golang.org/x/crypto/argon2"
)

// Argon2id parameters from docs/architecture.md: 64 MiB, two passes, four
// lanes, a 32-byte key and a 16-byte salt, stored as a PHC string.
const (
	argonTime    uint32 = 2
	argonMemory  uint32 = 64 * 1024
	argonThreads uint8  = 4
	argonKeyLen  uint32 = 32
	argonSaltLen        = 16
)

// ErrPasswordHash reports a stored hash that is not a valid argon2id PHC
// string. It is a server error, never a wrong password.
var ErrPasswordHash = errors.New("auth: malformed password hash")

// timingEqualizerHash is a well-formed PHC string with the parameters this
// package writes and a zero digest. Verifying a submitted password against it
// costs exactly one real check and always fails, so an unknown email answers
// no faster than a known one.
const timingEqualizerHash = "$argon2id$v=19$m=65536,t=2,p=4$MDEyMzQ1Njc4OWFiY2RlZg$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"

// HashPassword returns a new argon2id PHC string for password.
func HashPassword(password string) (string, error) {
	salt := make([]byte, argonSaltLen)
	if _, err := rand.Read(salt); err != nil {
		return "", fmt.Errorf("generate password salt: %w", err)
	}

	key := argon2.IDKey([]byte(password), salt, argonTime, argonMemory, argonThreads, argonKeyLen)

	return fmt.Sprintf("$argon2id$v=%d$m=%d,t=%d,p=%d$%s$%s",
		argon2.Version, argonMemory, argonTime, argonThreads,
		base64.RawStdEncoding.EncodeToString(salt),
		base64.RawStdEncoding.EncodeToString(key),
	), nil
}

// VerifyPassword reports whether password produced encoded. A malformed hash
// returns ErrPasswordHash; only a well-formed hash can answer "no".
func VerifyPassword(password, encoded string) (bool, error) {
	parts := strings.Split(encoded, "$")
	if len(parts) != 6 || parts[0] != "" || parts[1] != "argon2id" {
		return false, ErrPasswordHash
	}

	var version int
	if _, err := fmt.Sscanf(parts[2], "v=%d", &version); err != nil || version != argon2.Version {
		return false, ErrPasswordHash
	}

	var memory, iterations uint32
	var threads uint8
	if _, err := fmt.Sscanf(parts[3], "m=%d,t=%d,p=%d", &memory, &iterations, &threads); err != nil {
		return false, ErrPasswordHash
	}

	// A hash read from disk must not be able to ask for unbounded memory or
	// time. These ceilings are far above the parameters we write.
	if memory == 0 || memory > 1<<20 || iterations == 0 || iterations > 16 || threads == 0 || threads > 64 {
		return false, ErrPasswordHash
	}

	salt, err := base64.RawStdEncoding.DecodeString(parts[4])
	if err != nil || len(salt) < 8 {
		return false, ErrPasswordHash
	}
	digest, err := base64.RawStdEncoding.DecodeString(parts[5])
	if err != nil || len(digest) < 16 {
		return false, ErrPasswordHash
	}
	if uint32(len(digest)) > argonKeyLen*2 {
		return false, ErrPasswordHash
	}

	key := argon2.IDKey([]byte(password), salt, iterations, memory, threads, uint32(len(digest)))
	return subtle.ConstantTimeCompare(key, digest) == 1, nil
}
