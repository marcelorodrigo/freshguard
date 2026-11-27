package httpapi

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/auth"
)

const (
	adminName     = "Marcelo"
	adminEmail    = "m@example.com"
	adminPassword = "correct horse battery staple"
)

type testEnv struct {
	handler http.Handler
	auth    *auth.Service
	clock   *testClock
}

// newTestEnv builds the real routes plus a probe behind the session
// middleware, so a test can tell "must_reset blocked this" from "no route".
func newTestEnv(t *testing.T) *testEnv {
	t.Helper()

	clock := &testClock{now: testNow}
	svc, stock := testAuth(t, clock)

	srv := newServer(Options{
		Version:   "1.0.0",
		Logger:    slog.New(slog.DiscardHandler),
		Web:       testWeb(),
		Auth:      svc,
		Inventory: stock,
		Now:       clock.Now,
	})

	mux := srv.routes()
	mux.HandleFunc("GET /probe", srv.authenticated(
		func(w http.ResponseWriter, _ *http.Request, _ auth.User) {
			writeJSON(w, http.StatusOK, map[string]string{"probe": "ok"})
		},
	))

	return &testEnv{
		handler: logRequests(srv.logger, mux),
		auth:    svc,
		clock:   clock,
	}
}

type request struct {
	method  string
	path    string
	body    any
	cookie  *http.Cookie
	headers map[string]string
}

func (e *testEnv) send(t *testing.T, req request) *httptest.ResponseRecorder {
	t.Helper()

	var body io.Reader
	if req.body != nil {
		payload, err := json.Marshal(req.body)
		if err != nil {
			t.Fatalf("encode request body: %v", err)
		}
		body = bytes.NewReader(payload)
	}

	httpReq := httptest.NewRequest(req.method, req.path, body)
	if req.body != nil {
		httpReq.Header.Set("Content-Type", "application/json")
	}
	if req.cookie != nil {
		httpReq.AddCookie(req.cookie)
	}
	for key, value := range req.headers {
		httpReq.Header.Set(key, value)
	}

	rec := httptest.NewRecorder()
	e.handler.ServeHTTP(rec, httpReq)
	return rec
}

type userBody struct {
	ID        string `json:"id"`
	Name      string `json:"name"`
	Email     string `json:"email"`
	IsAdmin   bool   `json:"is_admin"`
	MustReset bool   `json:"must_reset"`
	CreatedAt string `json:"created_at"`
	UpdatedAt string `json:"updated_at"`
}

type sessionBody struct {
	User userBody `json:"user"`
}

func decodeBody[T any](t *testing.T, rec *httptest.ResponseRecorder) T {
	t.Helper()

	var body T
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode response %q: %v", rec.Body.String(), err)
	}
	return body
}

func decodeError(t *testing.T, rec *httptest.ResponseRecorder) errorBody {
	t.Helper()
	return decodeBody[errorResponse](t, rec).Error
}

func sessionCookie(t *testing.T, rec *httptest.ResponseRecorder) *http.Cookie {
	t.Helper()

	for _, cookie := range rec.Result().Cookies() {
		if cookie.Name == sessionCookieName {
			return cookie
		}
	}
	t.Fatalf("response set no %s cookie", sessionCookieName)
	return nil
}

func (e *testEnv) setup(t *testing.T) (*http.Cookie, userBody) {
	t.Helper()

	rec := e.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/setup",
		body: map[string]string{
			"name": adminName, "email": adminEmail, "password": adminPassword,
		},
	})
	if rec.Code != http.StatusCreated {
		t.Fatalf("setup status = %d, want 201: %s", rec.Code, rec.Body.String())
	}

	return sessionCookie(t, rec), decodeBody[sessionBody](t, rec).User
}

func TestSetupCreatesTheAdmin(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)

	before := env.send(t, request{method: http.MethodGet, path: "/api/v1/health"})
	if body := decodeBody[healthResponse](t, before); !body.SetupRequired {
		t.Error("setup_required before setup = false, want true")
	}

	rec := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/setup",
		body: map[string]string{
			"name": adminName, "email": adminEmail, "password": adminPassword,
		},
	})
	if rec.Code != http.StatusCreated {
		t.Fatalf("status = %d, want 201: %s", rec.Code, rec.Body.String())
	}

	user := decodeBody[sessionBody](t, rec).User
	if !user.IsAdmin {
		t.Error("the setup account is not an admin")
	}
	if user.Email != adminEmail || user.Name != adminName {
		t.Errorf("user = %+v, want the submitted name and email", user)
	}
	if user.MustReset {
		t.Error("must_reset = true on a fresh account")
	}
	if _, err := time.Parse(time.RFC3339, user.CreatedAt); err != nil {
		t.Errorf("created_at = %q, want RFC3339: %v", user.CreatedAt, err)
	}

	cookie := sessionCookie(t, rec)
	if !cookie.HttpOnly {
		t.Error("session cookie is not HttpOnly")
	}
	if cookie.SameSite != http.SameSiteLaxMode {
		t.Errorf("SameSite = %v, want Lax", cookie.SameSite)
	}
	if cookie.Path != "/" {
		t.Errorf("Path = %q, want /", cookie.Path)
	}
	if want := int(auth.DefaultSessionTTL.Seconds()); cookie.MaxAge != want {
		t.Errorf("MaxAge = %d, want %d (30 days)", cookie.MaxAge, want)
	}

	me := env.send(t, request{method: http.MethodGet, path: "/api/v1/me", cookie: cookie})
	if me.Code != http.StatusOK {
		t.Fatalf("GET /me status = %d, want 200: %s", me.Code, me.Body.String())
	}
	if got := decodeBody[userBody](t, me); got.Email != adminEmail {
		t.Errorf("GET /me email = %q, want %q", got.Email, adminEmail)
	}
	if strings.Contains(me.Body.String(), "password") {
		t.Errorf("GET /me leaked a password field: %s", me.Body.String())
	}

	after := env.send(t, request{method: http.MethodGet, path: "/api/v1/health"})
	if body := decodeBody[healthResponse](t, after); body.SetupRequired {
		t.Error("setup_required after setup = true, want false")
	}
}

func TestSetupOnlyRunsOnce(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.setup(t)

	rec := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/setup",
		body: map[string]string{
			"name": "Someone", "email": "someone@example.com", "password": adminPassword,
		},
	})
	if rec.Code != http.StatusConflict {
		t.Fatalf("second setup status = %d, want 409: %s", rec.Code, rec.Body.String())
	}
	if code := decodeError(t, rec).Code; code != CodeConflict {
		t.Errorf("second setup code = %q, want %q", code, CodeConflict)
	}
}

func TestSetupValidation(t *testing.T) {
	t.Parallel()

	tests := map[string]struct {
		body  map[string]string
		field string
		rule  string
	}{
		"missing name":   {body: map[string]string{"email": adminEmail, "password": adminPassword}, field: "name", rule: "required"},
		"blank name":     {body: map[string]string{"name": "  ", "email": adminEmail, "password": adminPassword}, field: "name", rule: "required"},
		"long name":      {body: map[string]string{"name": strings.Repeat("a", 256), "email": adminEmail, "password": adminPassword}, field: "name", rule: "max"},
		"missing email":  {body: map[string]string{"name": adminName, "password": adminPassword}, field: "email", rule: "required"},
		"invalid email":  {body: map[string]string{"name": adminName, "email": "not-an-email", "password": adminPassword}, field: "email", rule: "email"},
		"long email":     {body: map[string]string{"name": adminName, "email": strings.Repeat("a", 250) + "@example.com", "password": adminPassword}, field: "email", rule: "max"},
		"short password": {body: map[string]string{"name": adminName, "email": adminEmail, "password": "short"}, field: "password", rule: "min"},
	}

	for name, tc := range tests {
		t.Run(name, func(t *testing.T) {
			t.Parallel()

			env := newTestEnv(t)
			rec := env.send(t, request{method: http.MethodPost, path: "/api/v1/setup", body: tc.body})

			if rec.Code != http.StatusUnprocessableEntity {
				t.Fatalf("status = %d, want 422: %s", rec.Code, rec.Body.String())
			}
			err := decodeError(t, rec)
			if err.Code != CodeInvalidInput {
				t.Errorf("code = %q, want %q", err.Code, CodeInvalidInput)
			}
			if got := err.Fields[tc.field]; got != tc.rule {
				t.Errorf("fields[%s] = %q, want %q (fields = %v)", tc.field, got, tc.rule, err.Fields)
			}
		})
	}
}

func TestSetupRejectsMalformedJSON(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)

	req := httptest.NewRequest(http.MethodPost, "/api/v1/setup", strings.NewReader("{not json"))
	rec := httptest.NewRecorder()
	env.handler.ServeHTTP(rec, req)

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("status = %d, want 422", rec.Code)
	}
	if code := decodeError(t, rec).Code; code != CodeInvalidInput {
		t.Errorf("code = %q, want %q", code, CodeInvalidInput)
	}
}

func TestLoginReportsOneIdenticalError(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.setup(t)

	wrongPassword := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/session",
		body:   map[string]string{"email": adminEmail, "password": "not the password"},
	})
	unknownEmail := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/session",
		body:   map[string]string{"email": "nobody@example.com", "password": adminPassword},
	})

	if wrongPassword.Code != http.StatusUnauthorized || unknownEmail.Code != http.StatusUnauthorized {
		t.Fatalf("statuses = %d and %d, want both 401", wrongPassword.Code, unknownEmail.Code)
	}
	if wrongPassword.Body.String() != unknownEmail.Body.String() {
		t.Errorf("bodies differ:\n%s\n%s", wrongPassword.Body.String(), unknownEmail.Body.String())
	}
	if code := decodeError(t, wrongPassword).Code; code != CodeUnauthorized {
		t.Errorf("code = %q, want %q", code, CodeUnauthorized)
	}
}

func TestLoginOpensASession(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.setup(t)

	rec := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/session",
		body:   map[string]string{"email": adminEmail, "password": adminPassword},
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200: %s", rec.Code, rec.Body.String())
	}

	user := decodeBody[sessionBody](t, rec).User
	if user.Email != adminEmail {
		t.Errorf("user email = %q, want %q", user.Email, adminEmail)
	}

	me := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/me", cookie: sessionCookie(t, rec),
	})
	if me.Code != http.StatusOK {
		t.Errorf("GET /me status = %d, want 200", me.Code)
	}
}

func TestLoginIsRateLimited(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.setup(t)

	attempt := func(email string) *httptest.ResponseRecorder {
		return env.send(t, request{
			method: http.MethodPost,
			path:   "/api/v1/session",
			body:   map[string]string{"email": email, "password": "wrong password"},
		})
	}

	for i := range loginAttempts {
		if rec := attempt(adminEmail); rec.Code != http.StatusUnauthorized {
			t.Fatalf("attempt %d status = %d, want 401", i+1, rec.Code)
		}
	}

	rec := attempt(adminEmail)
	if rec.Code != http.StatusTooManyRequests {
		t.Fatalf("status after %d attempts = %d, want 429", loginAttempts+1, rec.Code)
	}
	if code := decodeError(t, rec).Code; code != CodeRateLimited {
		t.Errorf("code = %q, want %q", code, CodeRateLimited)
	}

	// The bucket is per address and email, not per client alone.
	if other := attempt("other@example.com"); other.Code != http.StatusUnauthorized {
		t.Errorf("a different email status = %d, want 401 (its own bucket)", other.Code)
	}
}

func TestLogoutRequiresOriginAndDeletesTheSession(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	noOrigin := env.send(t, request{
		method: http.MethodDelete, path: "/api/v1/session", cookie: cookie,
	})
	if noOrigin.Code != http.StatusForbidden {
		t.Fatalf("DELETE without Origin = %d, want 403", noOrigin.Code)
	}
	if code := decodeError(t, noOrigin).Code; code != CodeBadOrigin {
		t.Errorf("code = %q, want %q", code, CodeBadOrigin)
	}

	rec := env.send(t, request{
		method: http.MethodDelete, path: "/api/v1/session", cookie: cookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusNoContent {
		t.Fatalf("DELETE with Origin = %d, want 204: %s", rec.Code, rec.Body.String())
	}
	if cleared := sessionCookie(t, rec); cleared.MaxAge >= 0 {
		t.Errorf("cleared cookie MaxAge = %d, want negative", cleared.MaxAge)
	}

	me := env.send(t, request{method: http.MethodGet, path: "/api/v1/me", cookie: cookie})
	if me.Code != http.StatusUnauthorized {
		t.Errorf("GET /me after logout = %d, want 401 (the row is gone)", me.Code)
	}
}

func TestOriginCheck(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	tests := map[string]struct {
		headers map[string]string
		want    int
	}{
		"no origin":                 {headers: nil, want: http.StatusForbidden},
		"foreign origin":            {headers: map[string]string{"Origin": "http://evil.test"}, want: http.StatusForbidden},
		"null origin":               {headers: map[string]string{"Origin": "null"}, want: http.StatusForbidden},
		"matching origin":           {headers: map[string]string{"Origin": "http://example.com"}, want: http.StatusOK},
		"same-origin fetch":         {headers: map[string]string{"Sec-Fetch-Site": "same-origin"}, want: http.StatusOK},
		"cross-site fetch":          {headers: map[string]string{"Sec-Fetch-Site": "cross-site", "Origin": "http://example.com"}, want: http.StatusForbidden},
		"origin without the scheme": {headers: map[string]string{"Origin": "example.com"}, want: http.StatusForbidden},
	}

	for name, tc := range tests {
		t.Run(name, func(t *testing.T) {
			rec := env.send(t, request{
				method:  http.MethodPatch,
				path:    "/api/v1/me",
				body:    map[string]string{"name": "Ana"},
				cookie:  cookie,
				headers: tc.headers,
			})
			if rec.Code != tc.want {
				t.Errorf("status = %d, want %d: %s", rec.Code, tc.want, rec.Body.String())
			}
		})
	}
}

func TestSessionSurvivesReloadAndSlides(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	first := env.send(t, request{method: http.MethodGet, path: "/api/v1/me", cookie: cookie})
	if first.Code != http.StatusOK {
		t.Fatalf("first reload = %d, want 200", first.Code)
	}
	if set := first.Result().Cookies(); len(set) != 0 {
		t.Errorf("first reload re-issued the cookie, want it untouched for an hour")
	}

	env.clock.Advance(59 * time.Minute)
	early := env.send(t, request{method: http.MethodGet, path: "/api/v1/me", cookie: cookie})
	if early.Code != http.StatusOK {
		t.Fatalf("reload after 59 minutes = %d, want 200", early.Code)
	}
	if set := early.Result().Cookies(); len(set) != 0 {
		t.Error("the session slid before the refresh interval")
	}

	env.clock.Advance(2 * time.Hour)
	slid := env.send(t, request{method: http.MethodGet, path: "/api/v1/me", cookie: cookie})
	if slid.Code != http.StatusOK {
		t.Fatalf("reload after two hours = %d, want 200", slid.Code)
	}

	refreshed := sessionCookie(t, slid)
	if refreshed.Value != cookie.Value {
		t.Error("the refresh changed the secret, want the same session")
	}
	if want := int(auth.DefaultSessionTTL.Seconds()); refreshed.MaxAge != want {
		t.Errorf("refreshed MaxAge = %d, want %d", refreshed.MaxAge, want)
	}
}

func TestUnexpectedDatabaseErrorIs500(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	request := httptest.NewRequest(http.MethodGet, "/api/v1/me", nil).WithContext(ctx)
	request.AddCookie(cookie)
	rec := httptest.NewRecorder()
	env.handler.ServeHTTP(rec, request)

	if rec.Code != http.StatusInternalServerError {
		t.Fatalf("status = %d, want 500: %s", rec.Code, rec.Body.String())
	}
	if code := decodeError(t, rec).Code; code != CodeInternal {
		t.Errorf("code = %q, want %q", code, CodeInternal)
	}
	if strings.Contains(rec.Body.String(), "context canceled") {
		t.Errorf("body = %s, want the internal error kept out of the response", rec.Body.String())
	}
}

func TestInvalidSessionClearsTheCookie(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)

	rec := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/me",
		cookie: &http.Cookie{Name: sessionCookieName, Value: "made up"},
	})
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("status = %d, want 401", rec.Code)
	}
	if set := sessionCookie(t, rec); set.MaxAge >= 0 {
		t.Errorf("cleared cookie MaxAge = %d, want negative", set.MaxAge)
	}
}

func TestMustResetBlocksEverythingButProfileAndLogout(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.setup(t)

	member, err := env.auth.CreateUser(t.Context(), auth.CreateUserInput{
		Name: "Ana", Email: "ana@example.com", Password: adminPassword,
	})
	if err != nil {
		t.Fatalf("CreateUser: %v", err)
	}

	temporary := "temporary password"
	if err := env.auth.SetTemporaryPassword(t.Context(), member.ID, temporary); err != nil {
		t.Fatalf("SetTemporaryPassword: %v", err)
	}

	login := func() *http.Cookie {
		rec := env.send(t, request{
			method: http.MethodPost,
			path:   "/api/v1/session",
			body:   map[string]string{"email": member.Email, "password": temporary},
		})
		if rec.Code != http.StatusOK {
			t.Fatalf("login status = %d, want 200: %s", rec.Code, rec.Body.String())
		}
		if !decodeBody[sessionBody](t, rec).User.MustReset {
			t.Error("login response says must_reset = false, want true")
		}
		return sessionCookie(t, rec)
	}

	cookie := login()

	if rec := env.send(t, request{method: http.MethodGet, path: "/api/v1/me", cookie: cookie}); rec.Code != http.StatusOK {
		t.Errorf("GET /me = %d, want 200", rec.Code)
	}

	probe := env.send(t, request{method: http.MethodGet, path: "/probe", cookie: cookie})
	if probe.Code != http.StatusForbidden {
		t.Fatalf("GET /probe = %d, want 403", probe.Code)
	}
	if code := decodeError(t, probe).Code; code != CodeMustReset {
		t.Errorf("code = %q, want %q", code, CodeMustReset)
	}

	if rec := env.send(t, request{
		method: http.MethodDelete, path: "/api/v1/session", cookie: cookie,
		headers: map[string]string{"Origin": "http://example.com"},
	}); rec.Code != http.StatusNoContent {
		t.Errorf("sign out while must_reset = %d, want 204", rec.Code)
	}

	chosen := "the password they chose"
	secondLogin := login()
	update := env.send(t, request{
		method: http.MethodPatch,
		path:   "/api/v1/me",
		body: map[string]string{
			"password": chosen, "current_password": temporary,
		},
		cookie:  secondLogin,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if update.Code != http.StatusOK {
		t.Fatalf("PATCH /me password = %d, want 200: %s", update.Code, update.Body.String())
	}
	if user := decodeBody[userBody](t, update); user.MustReset {
		t.Error("must_reset = true after choosing a password, want false")
	}

	if rec := env.send(t, request{
		method: http.MethodGet, path: "/probe", cookie: secondLogin,
	}); rec.Code != http.StatusOK {
		t.Errorf("GET /probe after the reset = %d, want 200", rec.Code)
	}
}

func TestProfileUpdate(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	rec := env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/me",
		body:    map[string]string{"name": "Ana", "email": "ana@example.com"},
		cookie:  cookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200: %s", rec.Code, rec.Body.String())
	}

	user := decodeBody[userBody](t, rec)
	if user.Name != "Ana" || user.Email != "ana@example.com" {
		t.Errorf("user = %+v, want the patched values", user)
	}

	// An email already in use is a field rule, not a server error.
	if _, err := env.auth.CreateUser(t.Context(), auth.CreateUserInput{
		Name: "Other", Email: "other@example.com", Password: adminPassword,
	}); err != nil {
		t.Fatalf("CreateUser: %v", err)
	}

	taken := env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/me",
		body:    map[string]string{"email": "OTHER@example.com"},
		cookie:  cookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if taken.Code != http.StatusUnprocessableEntity {
		t.Fatalf("duplicate email status = %d, want 422", taken.Code)
	}
	if rule := decodeError(t, taken).Fields["email"]; rule != "unique" {
		t.Errorf("fields[email] = %q, want unique", rule)
	}

	missingCurrent := env.send(t, request{
		method: http.MethodPatch,
		path:   "/api/v1/me",
		body:   map[string]string{"password": "a much better password"},
		cookie: cookie,
		headers: map[string]string{
			"Origin": "http://example.com",
		},
	})
	if missingCurrent.Code != http.StatusUnprocessableEntity {
		t.Fatalf("password without current password = %d, want 422", missingCurrent.Code)
	}
	if rule := decodeError(t, missingCurrent).Fields["current_password"]; rule != "required" {
		t.Errorf("fields[current_password] = %q, want required", rule)
	}
}

func TestPasswordResetEndpoints(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	oldCookie, _ := env.setup(t)

	token, err := env.auth.CreateResetToken(t.Context(), adminEmail)
	if err != nil {
		t.Fatalf("CreateResetToken: %v", err)
	}

	lookup := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/password-resets/" + token.Secret,
	})
	if lookup.Code != http.StatusOK {
		t.Fatalf("GET reset token = %d, want 200: %s", lookup.Code, lookup.Body.String())
	}
	if body := decodeBody[resetTokenResponse](t, lookup); body.Email != adminEmail {
		t.Errorf("email = %q, want %q", body.Email, adminEmail)
	}

	unknown := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/password-resets/made-up",
	})
	if unknown.Code != http.StatusNotFound {
		t.Errorf("GET unknown token = %d, want 404", unknown.Code)
	}

	newPassword := "the password they chose"
	completed := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/password-resets/" + token.Secret,
		body:   map[string]string{"password": newPassword},
	})
	if completed.Code != http.StatusNoContent {
		t.Fatalf("POST reset = %d, want 204: %s", completed.Code, completed.Body.String())
	}

	reused := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/password-resets/" + token.Secret,
		body:   map[string]string{"password": newPassword},
	})
	if reused.Code != http.StatusNotFound {
		t.Errorf("POST the same token = %d, want 404 (single use)", reused.Code)
	}

	if me := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/me", cookie: oldCookie,
	}); me.Code != http.StatusUnauthorized {
		t.Errorf("old session after the reset = %d, want 401", me.Code)
	}

	login := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/session",
		body:   map[string]string{"email": adminEmail, "password": newPassword},
	})
	if login.Code != http.StatusOK {
		t.Errorf("login with the reset password = %d, want 200: %s", login.Code, login.Body.String())
	}
}
