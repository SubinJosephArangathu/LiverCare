# app.py — LiverCare AI API with second opinion logic
from flask import Flask, request, jsonify
import joblib, os, numpy as np, traceback, json, hashlib, time, warnings

warnings.filterwarnings("ignore")

# -----------------------------
# Optional SHAP import
# -----------------------------
try:
    import shap
    SHAP_AVAILABLE = True
except Exception:
    shap = None
    SHAP_AVAILABLE = False

app = Flask(__name__)
app.config['JSON_SORT_KEYS'] = False

# -----------------------------
# Paths and model loading
# -----------------------------
MODEL_DIR = os.environ.get("MODEL_DIR", "training_output")

MODEL_PATH = os.path.join(MODEL_DIR, "best_hcv_model.pkl")
SCALER_PATH = os.path.join(MODEL_DIR, "scaler.pkl")
LABEL_ENCODER_PATH = os.path.join(MODEL_DIR, "label_encoder.pkl")
FEATURE_ORDER_PATH = os.path.join(MODEL_DIR, "feature_order.pkl")
ALT_MODEL_PATH = os.path.join(MODEL_DIR, "alt_model.pkl")

if not os.path.exists(MODEL_PATH):
    raise FileNotFoundError(f"Model not found at {MODEL_PATH}")

best_model = joblib.load(MODEL_PATH)
scaler = joblib.load(SCALER_PATH)
label_encoder = joblib.load(LABEL_ENCODER_PATH)
feature_order = joblib.load(FEATURE_ORDER_PATH)
alt_model = joblib.load(ALT_MODEL_PATH) if os.path.exists(ALT_MODEL_PATH) else None
MODEL_VERSION = os.environ.get("MODEL_VERSION", "v1.0")

# -----------------------------
# SHAP Explainer initialization
# -----------------------------
_shap_explainer = None
_shap_last_init = 0

def get_shap_explainer():
    """Lazily initialize SHAP explainer."""
    global _shap_explainer, _shap_last_init
    if _shap_explainer is not None:
        return _shap_explainer

    now = time.time()
    if _shap_last_init and (now - _shap_last_init) < 10:
        return _shap_explainer
    _shap_last_init = now

    if not SHAP_AVAILABLE:
        print("⚠️ SHAP not available — skipping explainer initialization.")
        return None

    try:
        masker = shap.maskers.Independent(np.zeros((1, len(feature_order))))

        def model_predict_proba(X):
            X_scaled = scaler.transform(X)
            if hasattr(best_model, "predict_proba"):
                return best_model.predict_proba(X_scaled)
            try:
                df = best_model.decision_function(X_scaled)
                if df.ndim == 1:
                    probs_pos = 1.0 / (1.0 + np.exp(-df))
                    return np.vstack([1 - probs_pos, probs_pos]).T
                return df
            except Exception:
                return np.zeros((X.shape[0], 2))

        _shap_explainer = shap.Explainer(model_predict_proba, masker)
        print("✅ SHAP explainer initialized successfully.")
        return _shap_explainer

    except Exception as e:
        print("❌ Failed to create SHAP explainer:", e)
        return None

# -----------------------------
# Utility functions
# -----------------------------
def normalize_sex_value(v):
    if v is None:
        return None
    s = str(v).strip().lower()
    if s in ['m', 'male', '1', 'true', 't', 'yes', 'y']:
        return 1
    if s in ['f', 'female', '0', 'false', 'n', 'no']:
        return 0
    try:
        return 1 if int(float(s)) == 1 else 0
    except:
        return None

def compute_risk_level(prob):
    if prob >= 0.85:
        return "Low"
    if prob >= 0.65:
        return "Moderate"
    return "High"

def interpret_factor(feature, impact):
    try:
        trend = "increased the likelihood of disease" if impact > 0 else "reduced the likelihood of disease"
        strength = abs(float(impact))
        if strength > 0.5:
            level = "strongly"
        elif strength > 0.2:
            level = "moderately"
        else:
            level = "slightly"
        return f"{feature} {level} {trend} (impact: {impact:.3f})"
    except Exception:
        return f"{feature} had an impact of {impact}"

# -----------------------------
# SHAP / Top Factor Extraction
# -----------------------------
def compute_top_factors_shap(X_np):
    try:
        X_np = np.asarray(X_np).reshape(1, -1)
    except Exception:
        return []

    explainer = get_shap_explainer()
    if explainer is not None:
        try:
            shap_values_obj = explainer(X_np)
            vals = getattr(shap_values_obj, "values", None) or getattr(shap_values_obj, "shap_values", None)
            if vals is None:
                raise ValueError("No SHAP values found")

            if isinstance(vals, list):
                vals = np.array(vals[0])

            shap_vals = np.array(vals).reshape(-1)
            pairs = list(zip(feature_order, shap_vals))
            top = sorted(pairs, key=lambda x: abs(x[1]), reverse=True)[:3]
            return [{"feature": f, "impact": float(v), "explanation": interpret_factor(f, v)} for f, v in top]
        except Exception as e:
            print("⚠️ SHAP explanation failed:", e)

    # Fallback using scaled values
    try:
        X_scaled = scaler.transform(X_np)
        vals = X_scaled.squeeze()
        idxs = np.argsort(np.abs(vals))[::-1][:3]
        return [
            {"feature": feature_order[i], "impact": float(vals[i]), "explanation": interpret_factor(feature_order[i], vals[i])}
            for i in idxs
        ]
    except Exception as e:
        print("⚠️ Fallback top-factors failed:", e)
        return []

def generate_explanation_text(top_factors, pred_label, risk_level, probability):
    try:
        if not top_factors:
            return f"The model predicted {pred_label} with {risk_level} risk (confidence {probability*100:.2f}%)."
        factors_str = " ".join(
            f.get("explanation", interpret_factor(f.get("feature", "?"), f.get("impact", 0)))
            for f in top_factors
        )
        return f"The model predicted {pred_label} with {risk_level} risk (confidence {probability*100:.2f}%). {factors_str}"
    except Exception as e:
        print("generate_explanation_text error:", e)
        return f"The model predicted {pred_label} with {risk_level} risk (confidence {probability*100:.2f}%)."

# -----------------------------
# API ROUTES
# -----------------------------
@app.route("/", methods=["GET"])
def root():
    return jsonify({"message": "LiverCare API running", "model_version": MODEL_VERSION}), 200

@app.route("/api/predict", methods=["POST"])
def api_predict():
    try:
        data = request.get_json(force=True, silent=True)
        if not data:
            return jsonify({"error": "Invalid JSON"}), 400

        patient_id = data.get("patient_id", "").strip()
        if not patient_id:
            return jsonify({"error": "Missing patient_id"}), 400

        # Build feature array
        x_vals = []
        missing = []
        for feat in feature_order:
            if feat not in data:
                missing.append(feat)
            else:
                val = data[feat]
                if feat.lower() in ["sex", "gender"]:
                    v = normalize_sex_value(val)
                    if v is None:
                        return jsonify({"error": f"Invalid gender for {feat}: {val}"}), 400
                    x_vals.append(float(v))
                else:
                    try:
                        x_vals.append(float(val))
                    except Exception:
                        return jsonify({"error": f"Field {feat} must be numeric. Got: {val}"}), 400

        if missing:
            return jsonify({"error": f"Missing required fields: {missing}"}), 400

        X = np.array(x_vals).reshape(1, -1)
        X_scaled = scaler.transform(X)

        # Predict
        pred_enc = best_model.predict(X_scaled)[0]
        try:
            pred_label = label_encoder.inverse_transform([pred_enc])[0]
        except Exception:
            pred_label = str(pred_enc)

        if hasattr(best_model, "predict_proba"):
            probability = float(np.max(best_model.predict_proba(X_scaled)[0]))
        else:
            try:
                logit = best_model.decision_function(X_scaled)[0]
                probability = 1 / (1 + np.exp(-logit))
            except Exception:
                probability = 0.0

        risk_level = compute_risk_level(probability)
        top_factors = compute_top_factors_shap(X)
        explanation_text = generate_explanation_text(top_factors, pred_label, risk_level, probability)

        # -----------------------------
        # Second opinion logic
        # -----------------------------
        second_opinion = None
        if probability < 0.7:  # low confidence case
            if alt_model is not None:
                try:
                    alt_pred_enc = alt_model.predict(X_scaled)[0]
                    alt_label = label_encoder.inverse_transform([alt_pred_enc])[0]
                    alt_prob = float(np.max(alt_model.predict_proba(X_scaled)[0]))
                    second_opinion = {
                        "model": "alt_model",
                        "prediction": alt_label,
                        "probability": alt_prob
                    }
                except Exception as e:
                    print("⚠️ Second opinion model failed:", e)
                    second_opinion = {"note": "Alt model failed to generate second opinion"}
            else:
                second_opinion = {"note": "Confidence below 70%. Please verify with a medical professional."}

        # Hash for auditing
        payload_hash = hashlib.sha256(json.dumps({
            "patient_id": patient_id,
            "prediction": pred_label,
            "probability": probability,
            "risk_level": risk_level
        }, sort_keys=True).encode()).hexdigest()

        # Final response
        response = {
            "success": True,
            "prediction": pred_label,
            "probability": probability,
            "risk_level": risk_level,
            "top_factors": top_factors,
            "explanation_text": explanation_text,
            "hash": payload_hash,
            "model_version": MODEL_VERSION,
            "second_opinion": second_opinion
        }

        print("✅ DEBUG response keys:", list(response.keys()))
        return jsonify(response), 200

    except Exception as e:
        tb = traceback.format_exc()
        print("❌ Prediction Error:", e, tb)
        return jsonify({"success": False, "error": str(e)}), 500

# -----------------------------
# ENTRY POINT
# -----------------------------
if __name__ == "__main__":
    print(f"🚀 Starting LiverCare Flask API (Model {MODEL_VERSION}) on port 5000")
    app.run(host="0.0.0.0", port=5000)
