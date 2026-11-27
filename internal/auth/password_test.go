package auth

import (
	"encoding/base64"
	"errors"
	"strings"
	"testing"

	"golang.org/x/crypto/argon2"
)

func TestHashPassword(t *testing.T) {
	t.Parallel()

	hash, err := HashPassword("correct horse battery staple")
	if err != nil {
		t.Fatalf("HashPassword: %v", err)
	}

	if !strings.HasPrefix(hash, "$argon2id$v=19$m=65536,t=2,p=4$") {
		t.Errorf("hash = %q, want the documented argon2id parameters", hash)
	}

	other, err := HashPassword("correct horse battery staple")
	if err != nil {
		t.Fatalf("HashPassword twice: %v", err)
	}
	if hash == other {
		t.Error("two hashes of the same password are equal, want a fresh salt each time")
	}
}

func TestVerifyPassword(t *testing.T) {
	t.Parallel()

	hash, err := HashPassword("correct horse battery staple")
	if err != nil {
		t.Fatalf("HashPassword: %v", err)
	}

	ok, err := VerifyPassword("correct horse battery staple", hash)
	if err != nil {
		t.Fatalf("VerifyPassword: %v", err)
	}
	if !ok {
		t.Error("VerifyPassword with the right password = false, want true")
	}

	ok, err = VerifyPassword("wrong password", hash)
	if err != nil {
		t.Fatalf("VerifyPassword with a wrong password: %v", err)
	}
	if ok {
		t.Error("VerifyPassword with a wrong password = true, want false")
	}
}

func TestVerifyPasswordRejectsMalformedHashes(t *testing.T) {
	t.Parallel()

	tests := map[string]string{
		"empty":            "",
		"not argon2id":     "$argon2i$v=19$m=65536,t=2,p=4$c2FsdHNhbHQ$aGFzaA",
		"wrong version":    "$argon2id$v=16$m=65536,t=2,p=4$c2FsdHNhbHQ$aGFzaA",
		"bad params":       "$argon2id$v=19$m=nope,t=2,p=4$c2FsdHNhbHQ$aGFzaA",
		"bad salt":         "$argon2id$v=19$m=65536,t=2,p=4$!!!!$aGFzaA",
		"short digest":     "$argon2id$v=19$m=65536,t=2,p=4$c2FsdHNhbHQ$aGFzaA",
		"greedy memory":    "$argon2id$v=19$m=99999999,t=2,p=4$c2FsdHNhbHQ$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaA",
		"too many threads": "$argon2id$v=19$m=65536,t=2,p=200$c2FsdHNhbHQ$aGFzaGhhc2hoYXNoaGFzaGhhc2hoYXNoaGFzaA",
		"missing field":    "$argon2id$v=19$m=65536,t=2,p=4$c2FsdHNhbHQ",
	}

	for name, hash := range tests {
		ok, err := VerifyPassword("anything", hash)
		if !errors.Is(err, ErrPasswordHash) {
			t.Errorf("%s: err = %v, want ErrPasswordHash", name, err)
		}
		if ok {
			t.Errorf("%s: ok = true, want false", name)
		}
	}
}

// TestTimingEqualizerHash pins the dummy hash used for unknown emails: it must
// be well formed and cost a real verification, or the login flow would leak
// which addresses exist.
func TestTimingEqualizerHash(t *testing.T) {
	t.Parallel()

	ok, err := VerifyPassword("anything", timingEqualizerHash)
	if err != nil {
		t.Fatalf("VerifyPassword(timingEqualizerHash) = %v, want a well-formed hash", err)
	}
	if ok {
		t.Error("VerifyPassword(timingEqualizerHash) = true, want false")
	}
}

func base64Raw(value []byte) string {
	return base64.RawStdEncoding.EncodeToString(value)
}

func TestVerifyPasswordUsesTheStoredParameters(t *testing.T) {
	t.Parallel()

	// A hash written with different parameters must still verify: parameters
	// live in the string, not in this package's constants.
	salt := []byte("0123456789abcdef")
	key := argon2.IDKey([]byte("hunter2!"), salt, 1, 32*1024, 2, 16)
	encoded := "$argon2id$v=19$m=32768,t=1,p=2$" +
		base64Raw(salt) + "$" + base64Raw(key)

	ok, err := VerifyPassword("hunter2!", encoded)
	if err != nil {
		t.Fatalf("VerifyPassword: %v", err)
	}
	if !ok {
		t.Error("VerifyPassword = false, want true")
	}
}
