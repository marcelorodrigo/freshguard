package httpapi

import (
	"sync"
	"time"
)

const (
	// apiRequests and apiWindow implement the general bucket: 600 requests per
	// minute per address. Health is exempt.
	apiRequests = 600
	apiWindow   = time.Minute

	// limiterMaxEntries bounds the memory a limiter can hold when one client
	// sprays unique keys. The oldest window is evicted when the cap is hit.
	limiterMaxEntries = 10000
	// limiterSweepInterval keeps expiry cleanup from becoming an O(n) task on
	// every request.
	limiterSweepInterval = time.Minute
)

// rateLimiter is a fixed-window counter with bounded memory.
type rateLimiter struct {
	mu        sync.Mutex
	entries   map[string]rateEntry
	limit     int
	window    time.Duration
	lastSweep time.Time
}

type rateEntry struct {
	count int
	reset time.Time
}

func newRateLimiter(limit int, window time.Duration) *rateLimiter {
	return &rateLimiter{
		entries: make(map[string]rateEntry),
		limit:   limit,
		window:  window,
	}
}

func (l *rateLimiter) allow(key string, now time.Time) bool {
	l.mu.Lock()
	defer l.mu.Unlock()

	if l.lastSweep.IsZero() || now.Sub(l.lastSweep) >= limiterSweepInterval {
		l.sweep(now)
		l.lastSweep = now
	}

	entry, seen := l.entries[key]
	if seen && !now.Before(entry.reset) {
		delete(l.entries, key)
		seen = false
	}
	if !seen {
		if len(l.entries) >= limiterMaxEntries {
			l.sweep(now)
			if len(l.entries) >= limiterMaxEntries {
				l.evictOldest()
			}
		}
		l.entries[key] = rateEntry{count: 1, reset: now.Add(l.window)}
		return true
	}
	if entry.count >= l.limit {
		return false
	}

	entry.count++
	l.entries[key] = entry
	return true
}

func (l *rateLimiter) sweep(now time.Time) {
	for key, entry := range l.entries {
		if !now.Before(entry.reset) {
			delete(l.entries, key)
		}
	}
}

func (l *rateLimiter) evictOldest() {
	var (
		oldestKey  string
		oldestTime time.Time
	)
	for key, entry := range l.entries {
		if oldestKey == "" || entry.reset.Before(oldestTime) {
			oldestKey, oldestTime = key, entry.reset
		}
	}
	if oldestKey != "" {
		delete(l.entries, oldestKey)
	}
}
