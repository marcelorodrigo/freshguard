package httpapi

import (
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/auth"
)

type usersBody struct {
	Data []userBody `json:"data"`
	Meta pageMeta   `json:"meta"`
}

func (e *testEnv) createAccount(t *testing.T, name, email string, admin bool) auth.User {
	t.Helper()

	user, err := e.auth.CreateUser(t.Context(), auth.CreateUserInput{
		Name: name, Email: email, Password: adminPassword, IsAdmin: admin,
	})
	if err != nil {
		t.Fatalf("CreateUser(%s): %v", email, err)
	}
	return user
}

func (e *testEnv) signIn(t *testing.T, email, password string) *http.Cookie {
	t.Helper()

	rec := e.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/session",
		body:   map[string]string{"email": email, "password": password},
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("login %s = %d, want 200: %s", email, rec.Code, rec.Body.String())
	}
	return sessionCookie(t, rec)
}

func TestUsersAreAdminOnly(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.setup(t)
	member := env.createAccount(t, "Ana", "ana@example.com", false)
	memberCookie := env.signIn(t, member.Email, adminPassword)

	tests := []struct {
		name   string
		method string
		path   string
		body   any
	}{
		{name: "list", method: http.MethodGet, path: "/api/v1/users"},
		{name: "create", method: http.MethodPost, path: "/api/v1/users", body: map[string]any{
			"name": "Nope", "email": "nope@example.com", "password": adminPassword,
		}},
		{name: "update", method: http.MethodPatch, path: "/api/v1/users/" + member.ID, body: map[string]any{
			"name": "Nope",
		}},
		{name: "delete", method: http.MethodDelete, path: "/api/v1/users/" + member.ID},
		{name: "password", method: http.MethodPost, path: "/api/v1/users/" + member.ID + "/password", body: map[string]any{
			"password": "a temporary password",
		}},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			rec := env.send(t, request{
				method:  tc.method,
				path:    tc.path,
				body:    tc.body,
				cookie:  memberCookie,
				headers: map[string]string{"Origin": "http://example.com"},
			})
			if rec.Code != http.StatusForbidden {
				t.Fatalf("status = %d, want 403: %s", rec.Code, rec.Body.String())
			}
			if body := decodeError(t, rec); body.Code != CodeForbidden {
				t.Errorf("code = %q, want %q", body.Code, CodeForbidden)
			}
		})
	}
}

func listUsersEnv(t *testing.T) (*testEnv, *http.Cookie) {
	t.Helper()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	env.createAccount(t, "Ana", "ana@example.com", true)
	env.createAccount(t, "Bruno", "bruno@example.org", false)
	env.createAccount(t, "Carla", "carla@example.net", false)

	return env, cookie
}

func TestListUsersHTTP(t *testing.T) {
	t.Parallel()

	env, cookie := listUsersEnv(t)

	rec := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/users?sort=name&dir=asc&page=1&per_page=2",
		cookie: cookie,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200: %s", rec.Code, rec.Body.String())
	}

	body := decodeBody[usersBody](t, rec)
	if len(body.Data) != 2 {
		t.Fatalf("rows = %d, want 2", len(body.Data))
	}
	if body.Data[0].Name != "Ana" || body.Data[1].Name != "Bruno" {
		t.Errorf("names = %s, %s; want Ana, Bruno", body.Data[0].Name, body.Data[1].Name)
	}
	if body.Meta.Page != 1 || body.Meta.PerPage != 2 || body.Meta.Total != 4 || body.Meta.Pages != 2 {
		t.Errorf("meta = %+v, want page 1, per_page 2, total 4, pages 2", body.Meta)
	}
	if body.Data[0].Email != "ana@example.com" || !body.Data[0].IsAdmin {
		t.Errorf("row = %+v, want the listed email and admin flag", body.Data[0])
	}
}

func TestListUsersHTTPSearch(t *testing.T) {
	t.Parallel()

	env, cookie := listUsersEnv(t)

	searched := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/users?q=EXAMPLE.ORG",
		cookie: cookie,
	})
	if searched.Code != http.StatusOK {
		t.Fatalf("search status = %d, want 200", searched.Code)
	}
	found := decodeBody[usersBody](t, searched)
	if len(found.Data) != 1 || found.Data[0].Name != "Bruno" {
		t.Errorf("search rows = %+v, want only Bruno", found.Data)
	}
}

func TestListUsersHTTPSort(t *testing.T) {
	t.Parallel()

	env, cookie := listUsersEnv(t)

	admins := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/users?sort=is_admin&dir=desc",
		cookie: cookie,
	})
	if admins.Code != http.StatusOK {
		t.Fatalf("sort status = %d, want 200", admins.Code)
	}

	sorted := decodeBody[usersBody](t, admins)
	if len(sorted.Data) != 4 {
		t.Fatalf("rows = %d, want 4", len(sorted.Data))
	}
	if !sorted.Data[0].IsAdmin || !sorted.Data[1].IsAdmin || sorted.Data[2].IsAdmin {
		t.Errorf("sorted rows = %+v, want the two admins first", sorted.Data)
	}
	if sorted.Data[0].Name != "Ana" || sorted.Data[1].Name != "Marcelo" {
		t.Errorf("admin ordering = %s, %s; want Ana then Marcelo", sorted.Data[0].Name, sorted.Data[1].Name)
	}
}

func TestCreateUserHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	adminCookie, _ := env.setup(t)

	rec := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/users",
		body: map[string]any{
			"name": "Ana", "email": "ana@example.com",
			"password": adminPassword, "is_admin": true,
		},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusCreated {
		t.Fatalf("status = %d, want 201: %s", rec.Code, rec.Body.String())
	}
	created := decodeBody[userBody](t, rec)
	if created.Name != "Ana" || created.Email != "ana@example.com" || !created.IsAdmin {
		t.Errorf("user = %+v, want the submitted account", created)
	}
	if created.MustReset {
		t.Error("must_reset = true on a created account, want false")
	}

	env.signIn(t, "ana@example.com", adminPassword)

	missingName := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/users",
		body: map[string]any{
			"email": "missing@example.com", "password": adminPassword,
		},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if missingName.Code != http.StatusUnprocessableEntity {
		t.Fatalf("missing name status = %d, want 422", missingName.Code)
	}
	if rule := decodeError(t, missingName).Fields["name"]; rule != "required" {
		t.Errorf("fields[name] = %q, want required", rule)
	}

	duplicate := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/users",
		body: map[string]any{
			"name": "Other", "email": "ANA@example.com", "password": adminPassword,
		},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if duplicate.Code != http.StatusUnprocessableEntity {
		t.Fatalf("duplicate email status = %d, want 422", duplicate.Code)
	}
	if rule := decodeError(t, duplicate).Fields["email"]; rule != "unique" {
		t.Errorf("fields[email] = %q, want unique", rule)
	}
}

func TestAdminUpdateUserHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	adminCookie, admin := env.setup(t)
	member := env.createAccount(t, "Ana", "ana@example.com", false)
	memberCookie := env.signIn(t, member.Email, adminPassword)

	rec := env.send(t, request{
		method: http.MethodPatch,
		path:   "/api/v1/users/" + member.ID,
		body: map[string]any{
			"name": "Ana Maria", "email": "ana.maria@example.com", "is_admin": true,
		},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200: %s", rec.Code, rec.Body.String())
	}
	updated := decodeBody[userBody](t, rec)
	if updated.Name != "Ana Maria" || updated.Email != "ana.maria@example.com" || !updated.IsAdmin {
		t.Errorf("user = %+v, want the patched values", updated)
	}

	demote := env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/users/" + admin.ID,
		body:    map[string]any{"is_admin": false},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if demote.Code != http.StatusConflict {
		t.Fatalf("self demotion = %d, want 409: %s", demote.Code, demote.Body.String())
	}
	if code := decodeError(t, demote).Code; code != CodeCannotDemoteSelf {
		t.Errorf("code = %q, want %q", code, CodeCannotDemoteSelf)
	}

	// A password set by an admin on somebody else is temporary.
	temporary := "temporary password"
	password := env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/users/" + member.ID,
		body:    map[string]any{"password": temporary},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if password.Code != http.StatusOK {
		t.Fatalf("password patch = %d, want 200: %s", password.Code, password.Body.String())
	}
	if !decodeBody[userBody](t, password).MustReset {
		t.Error("must_reset = false after an admin password, want true")
	}
	if me := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/me", cookie: memberCookie,
	}); me.Code != http.StatusUnauthorized {
		t.Errorf("member's old session = %d, want 401", me.Code)
	}

	memberSession := env.signIn(t, "ana.maria@example.com", temporary)
	blocked := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/items", cookie: memberSession,
	})
	if blocked.Code != http.StatusForbidden {
		t.Fatalf("flagged member on items = %d, want 403", blocked.Code)
	}
	if code := decodeError(t, blocked).Code; code != CodeMustReset {
		t.Errorf("code = %q, want %q", code, CodeMustReset)
	}

	unknown := env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/users/0190a1b2-0000-7000-8000-000000000000",
		body:    map[string]any{"name": "Nope"},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if unknown.Code != http.StatusNotFound {
		t.Errorf("unknown user = %d, want 404", unknown.Code)
	}
}

func TestAdminPasswordOnSelfKeepsTheCallerSignedIn(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	adminCookie, admin := env.setup(t)

	chosen := "the admin chose this"
	rec := env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/users/" + admin.ID,
		body:    map[string]any{"password": chosen},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200: %s", rec.Code, rec.Body.String())
	}
	if decodeBody[userBody](t, rec).MustReset {
		t.Error("must_reset = true after changing own password, want false")
	}

	me := env.send(t, request{method: http.MethodGet, path: "/api/v1/me", cookie: adminCookie})
	if me.Code != http.StatusOK {
		t.Errorf("session after changing own password = %d, want 200", me.Code)
	}
	env.signIn(t, admin.Email, chosen)
}

func TestDeleteUserHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	adminCookie, admin := env.setup(t)
	member := env.createAccount(t, "Ana", "ana@example.com", false)

	rec := env.send(t, request{
		method:  http.MethodDelete,
		path:    "/api/v1/users/" + member.ID,
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d, want 204: %s", rec.Code, rec.Body.String())
	}
	if again := env.send(t, request{
		method: http.MethodDelete, path: "/api/v1/users/" + member.ID,
		cookie: adminCookie, headers: map[string]string{"Origin": "http://example.com"},
	}); again.Code != http.StatusNotFound {
		t.Errorf("second delete = %d, want 404", again.Code)
	}

	lastAdmin := env.send(t, request{
		method:  http.MethodDelete,
		path:    "/api/v1/users/" + admin.ID,
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if lastAdmin.Code != http.StatusConflict {
		t.Fatalf("self delete = %d, want 409: %s", lastAdmin.Code, lastAdmin.Body.String())
	}
	if code := decodeError(t, lastAdmin).Code; code != CodeLastAdmin {
		t.Errorf("code = %q, want %q", code, CodeLastAdmin)
	}
}

func TestDeleteSelfWithAnotherAdminIsRefused(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	adminCookie, admin := env.setup(t)
	env.createAccount(t, "Other admin", "other@example.com", true)

	rec := env.send(t, request{
		method:  http.MethodDelete,
		path:    "/api/v1/users/" + admin.ID,
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusConflict {
		t.Fatalf("status = %d, want 409: %s", rec.Code, rec.Body.String())
	}
	if code := decodeError(t, rec).Code; code != CodeCannotDeleteSelf {
		t.Errorf("code = %q, want %q", code, CodeCannotDeleteSelf)
	}
}

func TestSetTemporaryPasswordHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	adminCookie, _ := env.setup(t)
	member := env.createAccount(t, "Ana", "ana@example.com", false)
	memberCookie := env.signIn(t, member.Email, adminPassword)

	temporary := "temporary password"
	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/users/" + member.ID + "/password",
		body:    map[string]any{"password": temporary},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d, want 204: %s", rec.Code, rec.Body.String())
	}
	if me := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/me", cookie: memberCookie,
	}); me.Code != http.StatusUnauthorized {
		t.Errorf("member's old session = %d, want 401", me.Code)
	}

	signedIn := env.signIn(t, member.Email, temporary)
	blocked := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/items", cookie: signedIn,
	})
	if blocked.Code != http.StatusForbidden {
		t.Fatalf("flagged member on items = %d, want 403", blocked.Code)
	}
	if code := decodeError(t, blocked).Code; code != CodeMustReset {
		t.Errorf("code = %q, want %q", code, CodeMustReset)
	}

	short := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/users/" + member.ID + "/password",
		body:    map[string]any{"password": "short"},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if short.Code != http.StatusUnprocessableEntity {
		t.Fatalf("short password = %d, want 422", short.Code)
	}
	if rule := decodeError(t, short).Fields["password"]; rule != "min" {
		t.Errorf("fields[password] = %q, want min", rule)
	}
}

func TestSetTemporaryPasswordOnSelfKeepsTheCallerSession(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	adminCookie, admin := env.setup(t)

	temporary := "temporary password"
	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/users/" + admin.ID + "/password",
		body:    map[string]any{"password": temporary},
		cookie:  adminCookie,
		headers: map[string]string{"Origin": "http://example.com"},
	})
	if rec.Code != http.StatusNoContent {
		t.Fatalf("status = %d, want 204: %s", rec.Code, rec.Body.String())
	}

	me := env.send(t, request{method: http.MethodGet, path: "/api/v1/me", cookie: adminCookie})
	if me.Code != http.StatusOK {
		t.Fatalf("caller's session = %d, want 200", me.Code)
	}
	if !decodeBody[userBody](t, me).MustReset {
		t.Error("must_reset = false after a temporary password, want true")
	}
}

func TestExpiredResetLinkHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.setup(t)

	token, err := env.auth.CreateResetToken(t.Context(), adminEmail)
	if err != nil {
		t.Fatalf("CreateResetToken: %v", err)
	}

	env.clock.Advance(auth.PasswordResetTTL + time.Second)

	lookup := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/password-resets/" + token.Secret,
	})
	if lookup.Code != http.StatusNotFound {
		t.Errorf("GET expired token = %d, want 404: %s", lookup.Code, lookup.Body.String())
	}

	complete := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/password-resets/" + token.Secret,
		body:   map[string]string{"password": "the password they chose"},
	})
	if complete.Code != http.StatusNotFound {
		t.Errorf("POST expired token = %d, want 404: %s", complete.Code, complete.Body.String())
	}
	if strings.Contains(complete.Body.String(), "the password they chose") {
		t.Error("the error response echoes the password")
	}
}
