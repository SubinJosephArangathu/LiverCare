from flask import Flask, request, jsonify
import joblib
import numpy as np
import os, traceback
import warnings
warnings.filterwarnings("ignore", category=UserWarning)

app = Flask(__name__)
app.config['JSON_SORT_KEYS'] = False

# ===== Load model artifacts =====
MODEL_DIR = os.environ.get("MODEL_DIR", ".")
best_model = joblib.load(os.path.join(MODEL_DIR, "training_output/best_hcv_model.pkl"))
scaler = joblib.load(os.path.join(MODEL_DIR, "training_output/scaler.pkl"))
label_encoder = joblib.load(os.path.join(MODEL_DIR, "training_output/label_encoder.pkl"))
feature_order = joblib.load(os.path.join(MODEL_DIR, "training_output/feature_order.pkl"))

print("✅ Model artifacts loaded successfully.")
print("Expected feature order:", feature_order)


def normalize_sex_value(v):
    """Convert different forms of gender/sex input to numeric (1=Male, 0=Female)."""
    if v is None:
        return None
    s = str(v).strip().lower()
    if s in ['m', 'male', '1', 'true', 't', 'yes', 'y']:
        return 1
    if s in ['f', 'female', '0', 'false', 'n', 'no']:
        return 0
    try:
        iv = int(float(s))
        return 1 if iv == 1 else 0
    except:
        return None


@app.route("/", methods=["GET"])
def root():
    return jsonify({"message": "Liver ML API Running"}), 200


@app.route("/api/predict", methods=["POST"])
def api_predict():
    try:
        data = request.get_json(force=True, silent=True)
        if not data:
            return jsonify({"error": "Invalid or missing JSON"}), 400

        # Ensure patient_id exists
        patient_id = data.get("patient_id") or data.get("patient_name") or ""
        if not str(patient_id).strip():
            return jsonify({"error": "Missing patient_id"}), 400

        # Check and prepare features
        missing = []
        features = []
        for feat in feature_order:
            if feat not in data:
                missing.append(feat)
            else:
                val = data[feat]
                if feat.lower() in ["sex", "gender"]:
                    sex_v = normalize_sex_value(val)
                    if sex_v is None:
                        return jsonify({"error": f"Invalid gender value for {feat}: {val}"}), 400
                    features.append(float(sex_v))
                else:
                    try:
                        features.append(float(val))
                    except Exception:
                        return jsonify({"error": f"Field {feat} must be numeric. Got: {val}"}), 400

        if missing:
            return jsonify({"error": f"Missing required fields: {missing}"}), 400

        # ===== Perform prediction =====
        X = np.array(features).reshape(1, -1)
        X_scaled = scaler.transform(X)
        pred_enc = best_model.predict(X_scaled)[0]
        pred_label = label_encoder.inverse_transform([pred_enc])[0]

        if hasattr(best_model, "predict_proba"):
            probability = float(max(best_model.predict_proba(X_scaled)[0]))
        else:
            probability = 0.0

        # Return only model output — no DB writes
        return jsonify({
            "status": "success",
            "prediction": str(pred_label),
            "probability": probability
        }), 200

    except Exception as e:
        tb = traceback.format_exc()
        print("Prediction Error:", e, tb)
        return jsonify({"error": "Internal server error", "details": str(e)}), 500


if __name__ == "__main__":
    print("🚀 Starting Flask ML API")
    app.run(debug=True)
