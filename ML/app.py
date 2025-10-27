# app.py
from flask import Flask, request, jsonify
import mysql.connector
from mysql.connector import Error
import joblib
import numpy as np
from security.aes_secure import encrypt_text
import json, os, traceback

app = Flask(__name__)
app.config['JSON_SORT_KEYS'] = False

DB_CONFIG = {
    'host': 'localhost',
    'user': 'liveradmin',
    'password': 'LiverDB@123',
    'database': 'liver_cds'
}

def get_db_connection():
    try:
        return mysql.connector.connect(**DB_CONFIG)
    except Error as e:
        print("DB Error:", e)
        return None

# Load model artifacts (make sure these are the final /training_output/ files)
MODEL_DIR = os.environ.get("MODEL_DIR", ".")  # set MODEL_DIR env if needed
best_model = joblib.load(os.path.join(MODEL_DIR, "training_output/best_hcv_model.pkl"))
scaler = joblib.load(os.path.join(MODEL_DIR, "training_output/scaler.pkl"))
label_encoder = joblib.load(os.path.join(MODEL_DIR, "training_output/label_encoder.pkl"))
feature_order = joblib.load(os.path.join(MODEL_DIR, "training_output/feature_order.pkl"))  # list of strings

print("Loaded model artifacts. Expected feature order:", feature_order)

def normalize_sex_value(v):
    if v is None:
        return None
    s = str(v).strip().lower()
    if s in ['m', 'male', '1', 'true', 't', 'yes', 'y']:
        return 1
    if s in ['f', 'female', '0', 'false', 'false', 'n', 'no']:
        return 0
    # fallback: if numeric string
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

        # patient id presence
        patient_id = data.get("patient_id") or data.get("patient_name") or ""
        if not str(patient_id).strip():
            return jsonify({"error": "Missing patient_id"}), 400

        # Ensure all features present; also coerce Sex/Age types
        missing = []
        features = []
        for feat in feature_order:
            if feat not in data:
                missing.append(feat)
            else:
                val = data[feat]
                # treat Sex specially if the feature name indicates sex
                if feat.lower() in ["sex","gender"]:
                    sex_v = normalize_sex_value(val)
                    if sex_v is None:
                        return jsonify({"error": f"Field {feat} could not be interpreted as Sex (Male/Female/ m/f / 1/0)"}), 400
                    features.append(float(sex_v))
                else:
                    # numeric cast
                    try:
                        features.append(float(val))
                    except Exception:
                        return jsonify({"error": f"Field {feat} must be numeric. Value: {val}"}), 400

        if missing:
            return jsonify({"error": f"Missing required fields: {missing}"}), 400

        # scale and predict
        X = np.array(features).reshape(1, -1)
        Xs = scaler.transform(X)
        pred_enc = best_model.predict(Xs)[0]
        pred_label = label_encoder.inverse_transform([pred_enc])[0]

        if hasattr(best_model, "predict_proba"):
            probability = float(max(best_model.predict_proba(Xs)[0]))
        else:
            probability = 0.0

        # Save encrypted record to DB (attempt extended insert first)
        conn = get_db_connection()
        if conn is not None:
            try:
                cursor = conn.cursor()
                # Extended insert (expects these columns exist)
                try:
                    cursor.execute("""
                        INSERT INTO predictions (
                            user_id, reference_row_id, patient_id, age, gender,
                            ALB,ALP,ALT,AST,BIL,CHE,CHOL,CREA,GGT,PROT,
                            predicted_label, probability, source
                        ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                    """, (
                        0,  # system user
                        None,
                        encrypt_text(str(patient_id)),
                        encrypt_text(str(data.get("Age",""))),
                        encrypt_text(str(data.get("Sex",""))),
                        encrypt_text(str(data.get("ALB",""))),
                        encrypt_text(str(data.get("ALP",""))),
                        encrypt_text(str(data.get("ALT",""))),
                        encrypt_text(str(data.get("AST",""))),
                        encrypt_text(str(data.get("BIL",""))),
                        encrypt_text(str(data.get("CHE",""))),
                        encrypt_text(str(data.get("CHOL",""))),
                        encrypt_text(str(data.get("CREA",""))),
                        encrypt_text(str(data.get("GGT",""))),
                        encrypt_text(str(data.get("PROT",""))),
                        encrypt_text(str(pred_label)),
                        float(probability),
                        'api'
                    ))
                    conn.commit()
                except Exception as e_ext:
                    # fallback minimal insert
                    print("Extended DB insert failed:", e_ext)
                    try:
                        cursor.execute("""
                            INSERT INTO predictions (user_id, patient_id, predicted_label, probability, source)
                            VALUES (%s,%s,%s,%s,%s)
                        """, (0, encrypt_text(str(patient_id)), encrypt_text(str(pred_label)), float(probability), 'api'))
                        conn.commit()
                    except Exception as e_min:
                        print("Minimal DB insert failed:", e_min)
                finally:
                    cursor.close()
            except Exception as e:
                print("DB write failed:", e)
            try:
                conn.close()
            except:
                pass
        else:
            print("DB connection not available, skipped DB save.")

        return jsonify({"status": "success", "prediction": str(pred_label), "probability": probability}), 200

    except Exception as e:
        tb = traceback.format_exc()
        print("Prediction Error:", e, tb)
        return jsonify({"error": "Internal server error", "details": str(e)}), 500

if __name__ == "__main__":
    print("Starting Flask ML API")
    print("Feature order expected:", feature_order)
    app.run(debug=True)
