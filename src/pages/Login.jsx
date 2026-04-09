
import { useState, useContext } from "react";
import { useNavigate, Link } from "react-router-dom";
import { toast } from "react-toastify";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faEye, faEyeSlash } from "@fortawesome/free-solid-svg-icons";
import "../styles/auth.css";
import { authService } from "../services/authService";
import { AuthContext } from "../guards/context/AuthContext";

const Login = () => {
  const navigate = useNavigate();
  const { login } = useContext(AuthContext);

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  const handleLogin = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const res = await authService.login({ email, password });
      setLoading(false);

      if (!res.ok) return setError(res.data.message || "Login failed.");

      login(res.data.data.token, remember);
      toast.success("Login successful!");
      navigate("/dashboard");
    } catch {
      setLoading(false);
      setError("Something went wrong. Try again.");
    }
  };

  return (
    <div id="loginPage" className="page active">
      <div className="auth-container">
        <div className="auth-card">
          <div className="auth-header">
            <h1 className="auth-title">Welcome Back</h1>
            <p className="auth-subtitle">Sign in to your ScholarCompare account</p>
          </div>

          <form onSubmit={handleLogin} className="auth-form">
            <div className="form-group">
              <label className="form-label">Email Address</label>
              <input
                type="email"
                className="form-control"
                placeholder="your.email@university.edu"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>

            <div className="form-group">
              <label className="form-label">Password</label>
              <div className="password-input-group">
                <input
                  type={showPassword ? "text" : "password"}
                  className="form-control"
                  placeholder="Enter your password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                />
                <button
                  type="button"
                  className="password-toggle-btn"
                  onClick={() => setShowPassword(!showPassword)}
                >
                  <FontAwesomeIcon icon={showPassword ? faEyeSlash : faEye} />
                </button>
              </div>
            </div>

            <div className="form-check">
              <input
                type="checkbox"
                className="form-check-input"
                checked={remember}
                onChange={(e) => setRemember(e.target.checked)}
              />
              <label className="form-check-label">Remember me</label>
            </div>

            {error && <p className="auth-error">{error}</p>}

            <button type="submit" className="auth-btn" disabled={loading}>
              {loading ? (
                <span className="spinner" />
              ) : (
                "Sign In"
              )}
            </button>
          </form>

          <div className="auth-footer">
            <p className="auth-footer-links">
              Don't have an account? <Link to="/signup" className="auth-link">Create account</Link>
              <span className="auth-divider">|</span>
              <Link to="/forgot-password" className="auth-link">Forgot Password?</Link>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Login;
