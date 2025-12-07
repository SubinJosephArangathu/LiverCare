#!/usr/bin/env python3
# app.py — LiverCare API (Enhanced: friendly labels, professional risk scale, true confidence)
from flask import Flask, request, jsonify
import os, time, json, hashlib, traceback
import joblib
import numpy as np
import warnings
from math import log
warnings.filterwarnings("ignore")

# Optional SHAP import
try:
    import shap
    SHAP_AVAILABLE = True
except Exception:
    shap = None
    SHAP_AVAILABLE = False

app = Flask(__name__)
app.config['JSON_SORT_KEYS'] = False

# ---------------- Config ----------------
MODEL_DIR = os.environ.get("MODEL_DIR", "training_output")
MODEL_PATH = os.path.join(MODEL_DIR, "best_hcv_model.pkl")
ALT_MODEL_PATH = os.path.join(MODEL_DIR, "alt_model.pkl")
SCALER_PATH = os.path.join(MODEL_DIR, "scaler.pkl")
FEATURE_ORDER_PATH = os.path.join(MODEL_DIR, "feature_order.pkl")
LABEL_ENCODER_PATH = os.path.join(MODEL_DIR, "label_encoder.pkl")
MODEL_VERSION = os.environ.get("MODEL_VERSION", "v1.0")

# ---------------- Load artifacts ----------------
if not os.path.exists(MODEL_PATH):
    raise FileNotFoundError(f"Model not found at {MODEL_PATH}")

best_model = joblib.load(MODEL_PATH)
alt_model = joblib.load(ALT_MODEL_PATH) if os.path.exists(ALT_MODEL_PATH) else None
scaler = joblib.load(SCALER_PATH) if os.path.exists(SCALER_PATH) else None
feature_order = joblib.load(FEATURE_ORDER_PATH) if os.path.exists(FEATURE_ORDER_PATH) else None
label_encoder = joblib.load(LABEL_ENCODER_PATH) if os.path.exists(LABEL_ENCODER_PATH) else None

print("✅ Artifacts loaded. Feature order:", feature_order)

# SHAP lazy init
_shap_explainer = None
_shap_last_init = 0
def get_shap_explainer():
    global _shap_explainer, _shap_last_init
    if _shap_explainer is not None:
        return _shap_explainer
    if not SHAP_AVAILABLE:
        print("SHAP not available.")
        return None
    try:
        # safer masker: zeros with shape (1, n_features) — ideally use training sample
        masker = shap.maskers.Independent(np.zeros((1, len(feature_order))))
        def model_predict(X):
            Xs = scaler.transform(X)
            if hasattr(best_model, "predict_proba"):
                return best_model.predict_proba(Xs)
            try:
                df = best_model.decision_function(Xs)
                if df.ndim == 1:
                    prob_pos = 1.0 / (1.0 + np.exp(-df))
                    return np.vstack([1-prob_pos, prob_pos]).T
                return df
            except:
                return np.zeros((X.shape[0],2))
        _shap_explainer = shap.Explainer(model_predict, masker)
        print("SHAP explainer initialized.")
        return _shap_explainer
    except Exception as e:
        print("Failed SHAP init:", e)
        _shap_explainer = None
        return None

# ---------------- Helper utilities ----------------
def normalize_gender(v):
    if v is None: return None
    s = str(v).strip().lower()
    if s.startswith('m'): return 1
    if s.startswith('f'): return 0
    try:
        iv = int(float(s))
        return 1 if iv == 1 else 0
    except:
        return None

def safe_get_ag_ratio(data):
    # accept multiple variants from frontends: "A/G Ratio", "A_G", "AG_Ratio", "AGRatio"
    for k in ["A/G Ratio", "A_G", "AG_Ratio", "AGRatio", "A_G_Ratio"]:
        if k in data:
            try:
                return float(data[k])
            except:
                return 0.0
    return 0.0

def calculate_entropy(proba):
    # proba is iterable of class probabilities, sum ~1
    entropy = -sum([p * log(p + 1e-12) for p in proba])
    max_entropy = log(2)  # for binary
    return float(entropy / max_entropy)

def model_agreement_score(primary_conf, secondary_conf):
    if secondary_conf is None:
        return 0.5
    return 1.0 - abs(primary_conf - secondary_conf)

def shap_support_strength(shap_vals):
    if not shap_vals:
        return 0.5
    try:
        avg_abs = np.mean([abs(v["impact"]) for v in shap_vals])
        return float(min(1.0, avg_abs / 1.0))
    except:
        return 0.5

def interpret_factor(feature, impact):
    try:
        impact = float(impact)
        trend = "increased the likelihood of disease" if impact > 0 else "reduced the likelihood of disease"
        strength = abs(impact)
        if strength > 0.5:
            level = "strongly"
        elif strength > 0.2:
            level = "moderately"
        else:
            level = "slightly"
        return f"{feature} {level} {trend} (impact: {impact:.3f})"
    except:
        return f"{feature} impact {impact}"

def compute_top_factors(X_np):
    try:
        X_np = np.asarray(X_np)
        if X_np.ndim == 1:
            X_np = X_np.reshape(1, -1)
    except:
        return []
    explainer = get_shap_explainer()
    if explainer is not None:
        try:
            ev = explainer(X_np)
            vals = getattr(ev, "values", None) or getattr(ev, "shap_values", None)
            if vals is None:
                raise RuntimeError("No shap values")
            if isinstance(vals, list):
                vals_arr = np.asarray(vals[0])
            else:
                vals_arr = np.asarray(vals)
            shap_vals = vals_arr.reshape(1, -1)[0]
            pairs = list(zip(feature_order, shap_vals))
            top = sorted(pairs, key=lambda x: abs(x[1]), reverse=True)[:3]
            return [{"feature": f, "impact": float(v), "explanation": interpret_factor(f, v)} for f, v in top]
        except Exception as e:
            # fall through to fallback
            print("SHAP computation failed:", e)
    # fallback: scaled magnitudes
    try:
        Xs = scaler.transform(X_np)
        vals = Xs.squeeze()
        idxs = np.argsort(np.abs(vals))[::-1][:3]
        return [{"feature": feature_order[i], "impact": float(vals[i]), "explanation": interpret_factor(feature_order[i], vals[i])} for i in idxs]
    except Exception as e:
        print("Fallback top factors failed:", e)
        return []

# map numeric label -> human-friendly string
LABEL_MAP = {0: "No Liver Disease", 1: "Liver Disease"}

def get_class_index_for_value(model, value=1):
    # Try to find the column index for class value (e.g., 1)
    if hasattr(model, "classes_"):
        try:
            classes = list(model.classes_)
            return classes.index(value)
        except Exception:
            pass
    # fallback: assume index 1 corresponds to class 1
    return 1

def compute_risk_label(pred_index, disease_prob):
    """
    pred_index: the predicted class index (0 or 1)
    disease_prob: probability assigned to class 'disease' (class 1)
    returns human risk level string
    """
    pred_label = LABEL_MAP.get(pred_index, str(pred_index))
    if pred_index == 0:
        # No Liver Disease: use healthy_prob = 1 - disease_prob
        healthy_prob = 1.0 - disease_prob
        if healthy_prob > 0.85:
            return "Low"
        if healthy_prob >= 0.60:
            return "Medium"
        return "Borderline"
    else:
        # Liver Disease: use disease_prob to judge severity
        if disease_prob < 0.50:
            # unlikely, but if predicted 1 with low prob, mark Borderline
            return "Borderline"
        if disease_prob < 0.70:
            return "Mild"
        if disease_prob < 0.90:
            return "Moderate"
        return "High"

# ---------------- Routes ----------------
@app.route("/", methods=["GET"])
def root():
    return jsonify({"message":"LiverCare API running", "model_version": MODEL_VERSION}), 200

@app.route("/api/predict", methods=["POST"])
def api_predict():
    try:
        data = request.get_json(force=True, silent=True)
        if not data:
            return jsonify({"success": False, "error": "Invalid JSON"}), 400

        # build feature vector from feature_order (accept multiple names)
        missing = []
        x_vals = []
        for feat in feature_order:
            # accept alternative names for A/G
            if feat in data:
                raw = data[feat]
            else:
                # variations
                alt = None
                if feat in ["A_G", "A/G", "A/G Ratio", "A_G_Ratio", "AG_Ratio", "AGRatio"]:
                    alt = data.get("A/G Ratio") or data.get("A_G") or data.get("AG_Ratio") or data.get("AGRatio") or data.get("A_G_Ratio")
                elif feat.lower() in ["gender","sex"]:
                    alt = data.get("Gender") or data.get("gender") or data.get("Sex") or data.get("sex")
                else:
                    alt = data.get(feat)
                raw = alt
            if raw is None:
                missing.append(feat)
                continue
            # special handling for gender
            if str(feat).lower() in ["gender","sex"]:
                g = normalize_gender(raw)
                if g is None:
                    return jsonify({"success": False, "error": f"Invalid gender value for {feat}: {raw}"}), 400
                x_vals.append(float(g))
            else:
                try:
                    x_vals.append(float(raw))
                except Exception:
                    return jsonify({"success": False, "error": f"Field {feat} must be numeric. Got: {raw}"}), 400

        # Accept a few common alternate short-circuits: if missing only A/G variations, try combined getter
        if missing:
            # if A/G is the only missing and provided under other keys, handle that
            # attempt to map a common AG key names from data directly
            ag_keys = ["A/G Ratio","A_G","AG_Ratio","AGRatio","A_G_Ratio"]
            if set(missing) == {"A_G"} or set(missing) == set([k for k in missing if "A_G" in k or "A/G" in k]):
                found = None
                for k in ag_keys:
                    if k in data:
                        try:
                            found = float(data[k])
                        except:
                            found = None
                        break
                if found is not None:
                    # replace the missing A_G with value
                    try:
                        idx = feature_order.index("A_G")
                        x_vals.insert(idx, float(found))
                        missing = [m for m in missing if m != "A_G"]
                    except ValueError:
                        pass

        if missing:
            return jsonify({"success": False, "error": f"Missing required fields: {missing}"}), 400

        X = np.array(x_vals).reshape(1, -1)
        if scaler is not None:
            Xs = scaler.transform(X)
        else:
            Xs = X

        # Primary prediction
        if hasattr(best_model, "predict_proba"):
            probs = best_model.predict_proba(Xs)[0]
        else:
            try:
                df_val = best_model.decision_function(Xs)[0]
                if np.ndim(df_val) == 0:
                    prob_pos = 1.0 / (1.0 + np.exp(-df_val))
                    probs = np.array([1-prob_pos, prob_pos])
                else:
                    probs = df_val
            except:
                probs = np.array([0.5,0.5])

        # Determine index for disease class (class value 1)
        disease_index = get_class_index_for_value(best_model, 1)
        # probability of disease
        try:
            disease_prob = float(probs[disease_index])
        except:
            # fallback: if probs len==2 assume index 1
            disease_prob = float(probs[1]) if len(probs) > 1 else float(np.max(probs))

        pred_idx = int(np.argmax(probs))
        # Map to label strings using LABEL_MAP (0/1)
        pred_label_str = LABEL_MAP.get(pred_idx, str(pred_idx))

        # compute basic confidence (max probability)
        primary_conf = float(np.max(probs))

        # SHAP top factors
        top_factors = compute_top_factors(X)

        # Entropy / uncertainty based confidence component
        entropy_uncertainty = calculate_entropy(probs)
        entropy_confidence = 1 - entropy_uncertainty

        # Second opinion: ALWAYS run alt_model if available (for testing & full comparison)
        secondary_conf = None
        secondary_probs = None
        second_opinion_obj = None
        SECOND_OP_THRESHOLD = 0.70  # used for medical_warning only
        if primary_conf < SECOND_OP_THRESHOLD and alt_model is not None:
            try:
                if hasattr(alt_model, "predict_proba"):
                    secondary_probs = alt_model.predict_proba(Xs)[0]
                else:
                    try:
                        df_val2 = alt_model.decision_function(Xs)[0]
                        if np.ndim(df_val2) == 0:
                            ppos = 1.0 / (1.0 + np.exp(-df_val2))
                            secondary_probs = np.array([1-ppos, ppos])
                        else:
                            secondary_probs = df_val2
                    except:
                        secondary_probs = None
                if secondary_probs is not None:
                    # find disease prob index for alt_model
                    sec_disease_idx = get_class_index_for_value(alt_model, 1)
                    try:
                        secondary_conf = float(np.max(secondary_probs))
                        secondary_disease_prob = float(secondary_probs[sec_disease_idx])
                    except:
                        secondary_conf = float(np.max(secondary_probs))
                        secondary_disease_prob = None
                    sec_pred_idx = int(np.argmax(secondary_probs))
                    sec_label_str = LABEL_MAP.get(sec_pred_idx, str(sec_pred_idx))
                    second_opinion_obj = {
                        "model": "alt_model",
                        "prediction": sec_label_str,
                        "probability": secondary_disease_prob if secondary_disease_prob is not None else secondary_conf
                    }
            except Exception as e:
                second_opinion_obj = {"error": str(e)}
                secondary_conf = None

        # Model agreement and SHAP strength
        agreement = model_agreement_score(primary_conf, secondary_conf)
        shap_strength = shap_support_strength(top_factors)

        # Final combined confidence (weighted)
        final_confidence = (
            (primary_conf * 0.50) +
            (entropy_confidence * 0.20) +
            (agreement * 0.20) +
            (shap_strength * 0.10)
        )
        final_confidence = float(max(0.0, min(1.0, final_confidence)))

        # Compute risk label based on pred_idx and disease_prob
        risk_label = compute_risk_label(pred_idx, disease_prob)

        # Medical warning if both confidences < 0.70
        medical_warning = None
        if primary_conf < SECOND_OP_THRESHOLD:
            if secondary_conf is None:
                # alt model not available or failed
                medical_warning = None
            elif secondary_conf < SECOND_OP_THRESHOLD:
                medical_warning = "Both predictions have low confidence. Please consult a doctor or medical expert for further evaluation."

        # Food recommendations (simple rule)
        disease_flag = (pred_idx == 1)
        FOOD_RECOMMENDATIONS = {
            "liver_friendly": [
                "Leafy greens (spinach, kale)",
                "High-fiber whole grains (oats, brown rice)",
                "Lean proteins (chicken, fish)",
                "Fresh fruits (berries, apples)",
                "Healthy fats (olive oil, avocados)"
            ],
            "avoid": [
                "Alcohol",
                "High-fat fried foods",
                "Sugary beverages and sweets",
                "Processed meats",
                "Excess salt"
            ],
            "notes": "General guidelines. Consult a medical professional for personalized advice."
        }
        food_recs = FOOD_RECOMMENDATIONS if disease_flag else {"note":"No disease predicted — general healthy diet recommended."}

        # Build readable audit hash
        payload_for_hash = {
            "patient_id": data.get("patient_id", ""),
            "features": dict(zip(feature_order, [float(x) for x in x_vals])),
            "predicted_label": pred_label_str,
            "disease_probability": disease_prob
        }
        payload_hash = hashlib.sha256(json.dumps(payload_for_hash, sort_keys=True).encode()).hexdigest()

        # Build response
        response = {
            "success": True,
            "prediction": pred_label_str,
            "prediction_index": pred_idx,
            "probability_primary": primary_conf,
            "disease_probability": disease_prob,
            "risk_level": risk_label,
            "top_factors": top_factors,
            "explanation_text": f"The model predicted {pred_label_str} with {risk_label} risk and confidence of {primary_conf*100:.2f}%. " + " ".join([t.get("explanation","") for t in top_factors]),
            "confidence_original": primary_conf,
            "confidence_entropy_adjusted": entropy_confidence,
            "confidence_model_agreement": agreement,
            "confidence_shap_support": shap_strength,
            "confidence_final": final_confidence,
            "second_opinion": second_opinion_obj,
            "medical_warning": medical_warning,
            "food_recommendations": food_recs,
            "model_version": MODEL_VERSION,
            "hash": payload_hash
        }

        return jsonify(response), 200

    except Exception as e:
        tb = traceback.format_exc()
        print("Prediction Error:", e, tb)
        return jsonify({"success": False, "error": str(e), "trace": tb}), 500

if __name__ == "__main__":
    print("Starting LiverCare API on port 5000 (model_version:", MODEL_VERSION, ")")
    app.run(host="0.0.0.0", port=5000)
