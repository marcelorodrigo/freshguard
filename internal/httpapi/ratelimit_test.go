package httpapi

import (
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestRateLimiterWindow(t *testing.T) {
	t.Parallel()

	limiter := newRateLimiter(2, time.Minute)
	now := time.Date(2026, 1, 24, 8, 27, 26, 0, time.UTC)

	if !limiter.allow("client", now) {
		t.Fatal("the first request was refused")
	}
	if !limiter.allow("client", now) {
		t.Fatal("the second request was refused")
	}
	if limiter.allow("client", now) {
		t.Fatal("the third request was allowed, want the bucket full")
	}

	if !limiter.allow("client", now.Add(time.Minute)) {
		t.Fatal("the bucket did not reset after its window")
	}
}

func TestRateLimiterBoundsEntries(t *testing.T) {
	t.Parallel()

	limiter := newRateLimiter(1, time.Minute)
	now := time.Date(2026, 1, 24, 8, 27, 26, 0, time.UTC)

	for i := 0; i < limiterMaxEntries+10; i++ {
		limiter.allow("key-"+strconv.Itoa(i), now)
	}

	if got := len(limiter.entries); got != limiterMaxEntries {
		t.Errorf("entries = %d, want the cap %d", got, limiterMaxEntries)
	}
}

func TestLoginKeyHashesTheEmail(t *testing.T) {
	t.Parallel()

	req := httptest.NewRequest("POST", "/api/v1/session", nil)
	req.RemoteAddr = "192.0.2.1:1234"

	first := loginKey(req, "m@example.com")
	same := loginKey(req, "M@EXAMPLE.COM")
	if first != same {
		t.Error("the same email with different case hashed to different keys")
	}

	long := loginKey(req, strings.Repeat("a", 10000)+"@example.com")
	if len(long) != len(first) {
		t.Errorf("long email key length = %d, want the fixed %d", len(long), len(first))
	}
}
