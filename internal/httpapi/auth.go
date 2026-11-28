package httpapi

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/auth"
)

const (
	// sessionCookieName is the cookie documented in docs/architecture.md.
	sessionCookieName = "fg_session"
	// loginAttempts and loginWindow implement the login bucket: ten attempts
	// per fifteen minutes per address and email.
	loginAttempts = 10
	loginWindow   = 15 * time.Minute
	// maxRequestBody bounds a JSON body; nothing in v1 is close to a megabyte.
	maxRequestBody = 1 << 20
)

// userResponse is the User resource from docs/api.md.
type userResponse struct {
	ID        string `json:"id"`
	Name      string `json:"name"`
	Email     string `json:"email"`
	IsAdmin   bool   `json:"is_admin"`
	MustReset bool   `json:"must_reset"`
	CreatedAt string `json:"created_at"`
	UpdatedAt string `json:"updated_at"`
}

func newUserResponse(user auth.User) userResponse {
	return userResponse{
		ID:        user.ID,
		Name:      user.Name,
		Email:     user.Email,
		IsAdmin:   user.IsAdmin,
		MustReset: user.MustReset,
		CreatedAt: formatTimestamp(user.CreatedAt),
		UpdatedAt: formatTimestamp(user.UpdatedAt),
	}
}

// sessionResponse is what setup and login return: the user plus the cookie the
// response sets.
type sessionResponse struct {
	User userResponse `json:"user"`
}

type setupRequest struct {
	Name     string `json:"name"`
	Email    string `json:"email"`
	Password string `json:"password"`
}

// setup creates the first administrator. It is unauthenticated by design: the
// only thing protecting it is that it refuses to run once an account exists.
func (s *server) setup(w http.ResponseWriter, r *http.Request) {
	var req setupRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	session, err := s.auth.FirstAdmin(r.Context(), auth.CreateUserInput{
		Name:     req.Name,
		Email:    req.Email,
		Password: req.Password,
	})
	if err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "setup", err)
		return
	}

	s.setSessionCookie(w, r, session.Secret, session.ExpiresAt)
	writeJSON(w, http.StatusCreated, sessionResponse{User: newUserResponse(session.User)})
}

type loginRequest struct {
	Email    string `json:"email"`
	Password string `json:"password"`
}

func (s *server) login(w http.ResponseWriter, r *http.Request) {
	var req loginRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	if !s.loginLimiter.allow(loginKey(r, req.Email), s.now()) {
		writeError(w, http.StatusTooManyRequests, CodeRateLimited, "too many login attempts")
		return
	}

	session, err := s.auth.Login(r.Context(), req.Email, req.Password, r.UserAgent())
	if err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "login", err)
		return
	}

	s.setSessionCookie(w, r, session.Secret, session.ExpiresAt)
	writeJSON(w, http.StatusOK, sessionResponse{User: newUserResponse(session.User)})
}

// logout deletes the session server-side and clears the cookie. It is allowed
// while must_reset: a user who cannot sign out is trapped.
func (s *server) logout(w http.ResponseWriter, r *http.Request, _ auth.User) {
	if cookie, err := r.Cookie(sessionCookieName); err == nil {
		if err := s.auth.RevokeSession(r.Context(), cookie.Value); err != nil {
			s.internal(w, r, "logout", err)
			return
		}
	}

	s.clearSessionCookie(w, r)
	w.WriteHeader(http.StatusNoContent)
}

func (s *server) me(w http.ResponseWriter, _ *http.Request, user auth.User) {
	writeJSON(w, http.StatusOK, newUserResponse(user))
}

type profileRequest struct {
	Name            *string `json:"name"`
	Email           *string `json:"email"`
	Password        *string `json:"password"`
	CurrentPassword *string `json:"current_password"`
}

func (s *server) updateMe(w http.ResponseWriter, r *http.Request, user auth.User) {
	var req profileRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	updated, err := s.auth.UpdateProfile(r.Context(), user.ID, auth.ProfilePatch{
		Name:            req.Name,
		Email:           req.Email,
		Password:        req.Password,
		CurrentPassword: req.CurrentPassword,
	})
	if err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "update profile", err)
		return
	}

	writeJSON(w, http.StatusOK, newUserResponse(updated))
}

type resetTokenResponse struct {
	Email string `json:"email"`
}

func (s *server) resetToken(w http.ResponseWriter, r *http.Request) {
	email, err := s.auth.ResetTokenEmail(r.Context(), r.PathValue("token"))
	if err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "read password reset", err)
		return
	}

	writeJSON(w, http.StatusOK, resetTokenResponse{Email: email})
}

type resetPasswordRequest struct {
	Password string `json:"password"`
}

func (s *server) resetPassword(w http.ResponseWriter, r *http.Request) {
	var req resetPasswordRequest
	if !decodeJSON(w, r, &req) {
		return
	}

	if err := s.auth.ResetPassword(r.Context(), r.PathValue("token"), req.Password); err != nil {
		if writeAuthError(w, err) {
			return
		}
		s.internal(w, r, "complete password reset", err)
		return
	}

	w.WriteHeader(http.StatusNoContent)
}

// authedHandler runs with the session already resolved.
type authedHandler func(w http.ResponseWriter, r *http.Request, user auth.User)

// authenticated rejects a missing, expired or foreign-origin session before
// the handler sees the request. A must_reset account may only read and change
// its own profile and sign out.
func (s *server) authenticated(next authedHandler) http.HandlerFunc {
	return s.sessionHandler(false, next)
}

// administrator additionally requires the account to be an administrator.
func (s *server) administrator(next authedHandler) http.HandlerFunc {
	return s.sessionHandler(true, next)
}

func (s *server) sessionHandler(adminOnly bool, next authedHandler) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		session, ok := s.sessionFrom(w, r)
		if !ok {
			return
		}

		if session.User.MustReset && !allowedWhileMustReset(r) {
			writeError(w, http.StatusForbidden, CodeMustReset, "a new password is required")
			return
		}

		if adminOnly && !session.User.IsAdmin {
			writeError(w, http.StatusForbidden, CodeForbidden, "administrator access required")
			return
		}

		next(w, r, session.User)
	}
}

// sessionFrom resolves the cookie and origin, sliding the session when the
// service asks. It writes the error response and reports false to stop.
func (s *server) sessionFrom(w http.ResponseWriter, r *http.Request) (auth.Session, bool) {
	cookie, err := r.Cookie(sessionCookieName)
	if err != nil || cookie.Value == "" {
		writeError(w, http.StatusUnauthorized, CodeUnauthorized, "sign in required")
		return auth.Session{}, false
	}

	// A cookie is ambient authority: a cross-site form post would carry it.
	// Browsers label those requests, and the label must agree with this host.
	if r.Method != http.MethodGet && r.Method != http.MethodHead && !sameOrigin(r) {
		writeError(w, http.StatusForbidden, CodeBadOrigin, "origin does not match this host")
		return auth.Session{}, false
	}

	session, err := s.auth.Session(r.Context(), cookie.Value)
	if err != nil {
		if errors.Is(err, auth.ErrSessionInvalid) {
			s.clearSessionCookie(w, r)
			writeError(w, http.StatusUnauthorized, CodeUnauthorized, "invalid or expired session")
			return auth.Session{}, false
		}
		s.internal(w, r, "session", err)
		return auth.Session{}, false
	}

	if session.Extended {
		s.setSessionCookie(w, r, cookie.Value, session.ExpiresAt)
	}

	return session, true
}

// allowedWhileMustReset is the short list docs/api.md permits a flagged
// account: its own profile and signing out.
func allowedWhileMustReset(r *http.Request) bool {
	switch {
	case r.URL.Path == "/api/v1/me":
		return r.Method == http.MethodGet || r.Method == http.MethodPatch
	case r.URL.Path == "/api/v1/session":
		return r.Method == http.MethodDelete
	default:
		return false
	}
}

// sameOrigin reports whether the browser's own labels say this request came
// from this host. Sec-Fetch-Site is preferred; Origin is the fallback.
func sameOrigin(r *http.Request) bool {
	site := r.Header.Get("Sec-Fetch-Site")
	if site == "same-origin" {
		return true
	}
	if site != "" {
		return false
	}

	// Older browsers send only Origin.
	origin := r.Header.Get("Origin")
	if origin == "" {
		return false
	}

	parsed, err := url.Parse(origin)
	if err != nil {
		return false
	}
	return strings.EqualFold(parsed.Host, r.Host)
}

func (s *server) setSessionCookie(w http.ResponseWriter, r *http.Request, secret string, expires time.Time) {
	maxAge := int(expires.Sub(s.now()).Seconds())
	if maxAge < 1 {
		maxAge = 1
	}

	// Secure follows the request, not a proxy header: TLS may terminate in
	// front of the server, and the Android shell loads a plain-HTTP LAN
	// address where a Secure cookie would be dropped.
	http.SetCookie(w, &http.Cookie{ // NOSONAR
		Name:     sessionCookieName,
		Value:    secret,
		Path:     "/",
		HttpOnly: true,
		Secure:   r.TLS != nil,
		SameSite: http.SameSiteLaxMode,
		Expires:  expires,
		MaxAge:   maxAge,
	})
}

func (s *server) clearSessionCookie(w http.ResponseWriter, r *http.Request) {
	http.SetCookie(w, &http.Cookie{ // NOSONAR
		Name:     sessionCookieName,
		Value:    "",
		Path:     "/",
		HttpOnly: true,
		Secure:   r.TLS != nil,
		SameSite: http.SameSiteLaxMode,
		Expires:  time.Unix(1, 0).UTC(),
		MaxAge:   -1,
	})
}

// writeAuthError maps an auth rule to the envelope. It reports false for an
// unexpected error, which the caller logs and answers as 500.
func writeAuthError(w http.ResponseWriter, err error) bool {
	var fieldErr *auth.FieldError

	switch {
	case errors.As(err, &fieldErr):
		writeJSON(w, http.StatusUnprocessableEntity, errorResponse{Error: errorBody{
			Code:    CodeInvalidInput,
			Message: "invalid input",
			Fields:  map[string]string{fieldErr.Field: fieldErr.Rule},
		}})
	case errors.Is(err, auth.ErrSetupComplete):
		writeError(w, http.StatusConflict, CodeConflict, "setup is already complete")
	case errors.Is(err, auth.ErrInvalidCredentials):
		writeError(w, http.StatusUnauthorized, CodeUnauthorized, "invalid credentials")
	case errors.Is(err, auth.ErrSessionInvalid):
		writeError(w, http.StatusUnauthorized, CodeUnauthorized, "invalid or expired session")
	case errors.Is(err, auth.ErrResetTokenInvalid), errors.Is(err, auth.ErrUserNotFound):
		writeError(w, http.StatusNotFound, CodeNotFound, "no such resource")
	case errors.Is(err, auth.ErrForbidden):
		writeError(w, http.StatusForbidden, CodeForbidden, "administrator access required")
	case errors.Is(err, auth.ErrCannotDeleteSelf):
		writeError(w, http.StatusConflict, CodeCannotDeleteSelf, "you cannot delete your own account")
	case errors.Is(err, auth.ErrLastAdmin):
		writeError(w, http.StatusConflict, CodeLastAdmin, "the last administrator cannot be deleted")
	case errors.Is(err, auth.ErrCannotDemoteSelf):
		writeError(w, http.StatusConflict, CodeCannotDemoteSelf, "you cannot remove your own administrator rights")
	default:
		return false
	}

	return true
}

func (s *server) internal(w http.ResponseWriter, r *http.Request, operation string, err error) {
	s.logger.LogAttrs(r.Context(), slog.LevelError, "request failed",
		slog.String("operation", operation),
		slog.String("error", err.Error()),
	)
	writeError(w, http.StatusInternalServerError, CodeInternal, "internal error")
}

// decodeJSON reads a request body, answering the envelope when it is not JSON.
func decodeJSON(w http.ResponseWriter, r *http.Request, dst any) bool {
	decoder := json.NewDecoder(http.MaxBytesReader(w, r.Body, maxRequestBody))
	if err := decoder.Decode(dst); err != nil {
		writeError(w, http.StatusUnprocessableEntity, CodeInvalidInput, "request body must be JSON")
		return false
	}
	return true
}

func formatTimestamp(t time.Time) string {
	return t.UTC().Format(time.RFC3339)
}

// loginKey scopes the bucket to the caller and the address being tried, so one
// noisy client cannot lock every account out and one account cannot be hammered.
// The address is hashed so a spray of long emails cannot grow the key.
func loginKey(r *http.Request, email string) string {
	sum := sha256.Sum256([]byte(strings.ToLower(strings.TrimSpace(email))))
	return clientIP(r) + "\x00" + hex.EncodeToString(sum[:])
}

func clientIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}
