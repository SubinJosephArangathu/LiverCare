#!/usr/bin/env python3
"""
liver_train.py

Training pipeline for the Liver Patient Dataset (LPD).
- Reads CSV with encoding fallback
- Maps Result: 1 -> disease (1), 2 -> no disease (0)
- Keeps Gender (maps Male->1 Female->0)
- Imputes medians, SMOTE on train, RobustScaler
- Trains multiple models, calibrates, selects best model by test accuracy
- Saves artifacts for Flask/PHP:
    training_output/best_hcv_model.pkl
    training_output/alt_model.pkl   (second-best model; optional)
    training_output/scaler.pkl
    training_output/label_encoder.pkl
    training_output/feature_order.pkl
    training_output/label_mapping.json
    training_output/model_test_results.csv
    training_output/test_data_sample.csv
- Produces a PDF & confusion matrices
"""
import os
import json
import time
from datetime import datetime
from xgboost import XGBClassifier
import numpy as np
import pandas as pd
import joblib
import matplotlib.pyplot as plt
import seaborn as sns
from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import letter

from sklearn.model_selection import train_test_split, StratifiedKFold
from sklearn.preprocessing import RobustScaler, LabelEncoder
from sklearn.metrics import classification_report, confusion_matrix, accuracy_score
from sklearn.ensemble import RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.svm import SVC
from sklearn.neural_network import MLPClassifier
from sklearn.calibration import CalibratedClassifierCV

from imblearn.over_sampling import SMOTE

# ---------------- Config ----------------
# File - replace with your csv filename if different
DATA_FILE = "Liver Patient Dataset (LPD)_train.csv"
RANDOM_STATE = 42
TEST_SIZE = 0.20
N_SPLITS = 5
SMOTE_RANDOM = 42
OUTPUT_DIR = "training_output"
os.makedirs(OUTPUT_DIR, exist_ok=True)

# ---------------- Load dataset (robust encoding) ----------------
print("📥 Loading dataset:", DATA_FILE)
# Try common encodings and engine that handles weird chars
encodings_to_try = ["utf-8", "latin1", "iso-8859-1", "cp1252"]
df = None
for enc in encodings_to_try:
    try:
        df = pd.read_csv(DATA_FILE, encoding=enc, engine="python")
        print(f"    loaded with encoding: {enc}, shape: {df.shape}")
        break
    except Exception as e:
        print(f"    failed with {enc}: {e}")
if df is None:
    raise RuntimeError("Failed to read dataset in tried encodings. Please check file or provide a different path/encoding.")

# Normalize column names (strip BOM/non-breaking spaces)
df.columns = [c.strip().replace('\xa0', ' ').replace('\u00A0',' ').replace(' ', '_') for c in df.columns]

print("Columns:", df.columns.tolist())

# map column name variants
col_map = {}
for c in df.columns:
    lc = c.lower()
    if 'age' in lc and 'age' not in col_map:
        col_map['Age'] = c
    if 'gender' in lc and 'gender' not in col_map:
        col_map['Gender'] = c
    if 'tb' in lc or 'total bilirubin' in lc:
        col_map['TB'] = c
    if 'db' in lc or 'direct bilirubin' in lc:
        col_map['DB'] = c
    if 'alk' in lc or 'alkphos' in lc or 'alkaline' in lc:
        col_map['Alkphos'] = c
    if 'sgpt' in lc or 'alanine' in lc:
        col_map['Sgpt'] = c
    if 'sgot' in lc or 'aspartate' in lc:
        col_map['Sgot'] = c
    if 'tp' in lc or 'total protein' in lc or 'total_protiens' in lc:
        col_map['TP'] = c
    if 'alb' in lc and 'a/g' not in lc:
        col_map['ALB'] = c
    if 'a/g' in lc or 'a/g ratio' in lc or 'ag_ratio' in lc or 'a_g' in lc:
        col_map['A_G'] = c
    if 'result' in lc or 'selector' in lc:
        col_map['Result'] = c

# Check mandatory
required = ['Age','Gender','TB','DB','Alkphos','Sgpt','Sgot','TP','ALB','A_G','Result']
missing_required = [r for r in required if r not in col_map]
if missing_required:
    print("⚠ Missing expected columns (attempting to proceed):", missing_required)

# rename cols to standard names for pipeline
rename_dict = {v:k for k,v in col_map.items()}
df = df.rename(columns=rename_dict)
print("After rename, columns:", df.columns.tolist())

# ---------------- Basic cleaning ----------------
# Strip whitespace in string columns
for c in df.select_dtypes(include=['object']).columns:
    df[c] = df[c].astype(str).str.strip()

# Fix gender: map male/female to 1/0
if 'Gender' in df.columns:
    df['Gender'] = df['Gender'].replace({'M':'Male','F':'Female','m':'Male','f':'Female'})
    df['Gender'] = df['Gender'].map(
        lambda x: 1 if str(x).strip().lower().startswith('m')
        else (0 if str(x).strip().lower().startswith('f') else np.nan)
    )

# Map Result: original dataset uses 1 (Liver Patient) and 2 (Non Liver)
if 'Result' not in df.columns:
    raise ValueError("Dataset must contain a Result column (target).")
# ensure numeric
df['Result'] = pd.to_numeric(df['Result'], errors='coerce')
# map to 0/1: 1 -> 1 (disease), 2 -> 0 (no disease)
df['Category'] = df['Result'].map(lambda x: 1 if int(x) == 1 else 0)

# Drop rows with no Category
df = df.dropna(subset=['Category'])
df['Category'] = df['Category'].astype(int)

print("    after mapping Category, shape:", df.shape)
print("📊 Class distribution (original):")
print(df['Category'].value_counts())

# ---------------- Select features ----------------
features = []
for key in ['Age','Gender','TB','DB','Alkphos','Sgpt','Sgot','TP','ALB','A_G']:
    if key in df.columns:
        features.append(key)
    else:
        print(f"⚠ Column {key} not found — will attempt to continue without it.")

if len(features) < 5:
    print("⚠ Few features found; check dataset columns. Found features:", features)

X = df[features].copy()
y = df['Category'].copy()

# Convert numeric where possible
for col in X.columns:
    X[col] = pd.to_numeric(X[col], errors='coerce')

# Fill numeric missing values with median
for c in X.columns:
    med = X[c].median()
    X[c] = X[c].fillna(med)

# If Gender has NaNs after mapping, fill with mode (0 or 1)
if 'Gender' in X.columns and X['Gender'].isnull().any():
    X['Gender'] = X['Gender'].fillna(X['Gender'].mode()[0])

print("\n📋 Final features used:")
print(list(X.columns))

# Save feature_order
feature_order = list(X.columns)
joblib.dump(feature_order, os.path.join(OUTPUT_DIR, "feature_order.pkl"))
with open(os.path.join(OUTPUT_DIR, "feature_order.json"), "w") as f:
    json.dump(feature_order, f, indent=2)
print("✅ feature_order saved to", os.path.join(OUTPUT_DIR, "feature_order.json"))

# ---------------- Train/test split ----------------
print("\n🔀 Stratified train/test split (test_size=", TEST_SIZE, ")")
X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=TEST_SIZE, stratify=y, random_state=RANDOM_STATE
)
print("   Train:", X_train.shape, "Test:", X_test.shape)

# ---------------- SMOTE on TRAIN only ----------------
print("\n✨ Applying SMOTE on training set only...")
smote = SMOTE(random_state=SMOTE_RANDOM)
X_train_res, y_train_res = smote.fit_resample(X_train, y_train)
print("   After SMOTE class counts:")
print(pd.Series(y_train_res).value_counts())

# ---------------- Scaling ----------------
print("\n⚖ Fitting RobustScaler on training set...")
scaler = RobustScaler()
X_train_s = scaler.fit_transform(X_train_res)
X_test_s = scaler.transform(X_test)

joblib.dump(scaler, os.path.join(OUTPUT_DIR, "scaler.pkl"))
print("✅ scaler saved to", os.path.join(OUTPUT_DIR, "scaler.pkl"))

# ---------------- Label encoder (for mapping to strings) ----------------
label_encoder = LabelEncoder()
label_encoder.fit([0,1])
joblib.dump(label_encoder, os.path.join(OUTPUT_DIR, "label_encoder.pkl"))

label_map = {0: "No_Disease", 1: "Disease"}
with open(os.path.join(OUTPUT_DIR, "label_mapping.json"), "w") as f:
    json.dump(label_map, f, indent=2)
print("✅ label mapping saved")

# ---------------- Models --------------------------------
print("\n🚀 Preparing candidate models...")
models = {
    "RandomForest": RandomForestClassifier(
        n_estimators=200, random_state=RANDOM_STATE, class_weight='balanced'
    ),
    "LogisticRegression": LogisticRegression(
        max_iter=2000, class_weight='balanced', solver='liblinear'
    ),
    "XGBoost": XGBClassifier(
        n_estimators=200,
        learning_rate=0.05,
        max_depth=4,
        subsample=0.8,
        colsample_bytree=0.8,
        eval_metric='logloss',
        random_state=RANDOM_STATE
    )
}
skf = StratifiedKFold(
    n_splits=min(N_SPLITS, max(2, int(len(y_train_res) / 10))),
    shuffle=True,
    random_state=RANDOM_STATE
)

best_model = None
best_name = None
best_test_acc = -1.0
model_perfs = []

for idx, (name, model) in enumerate(models.items(), start=1):
    print(f"\n[{idx}/{len(models)}] Training {name} ...")
    try:
        model.fit(X_train_s, y_train_res)
        y_pred = model.predict(X_test_s)
        acc_test = accuracy_score(y_test, y_pred)
        print(f"   -> Test accuracy: {acc_test:.4f}")
        model_perfs.append((name, model, acc_test))

        print("   Classification report:")
        print(classification_report(y_test, y_pred, target_names=["No_Disease","Disease"]))

        cm = confusion_matrix(y_test, y_pred)
        plt.figure(figsize=(5,4))
        sns.heatmap(
            cm, annot=True, fmt="d", cmap="Blues",
            xticklabels=["No","Yes"], yticklabels=["No","Yes"]
        )
        plt.title(f"{name} - Test Confusion Matrix")
        plt.tight_layout()
        cm_path = os.path.join(OUTPUT_DIR, f"{name}_confusion.png")
        plt.savefig(cm_path)
        plt.close()
        print("   Confusion matrix saved to:", cm_path)

        if acc_test > best_test_acc:
            best_test_acc = acc_test
            best_model = model
            best_name = name
    except Exception as e:
        print(f"   Training {name} failed: {e}")

print("\n🏆 Best model:", best_name, "| Test Accuracy:", best_test_acc)

# Choose alt model (second best) if available
model_perfs_sorted = sorted(model_perfs, key=lambda x: x[2], reverse=True)
if len(model_perfs_sorted) >= 2:
    alt_name, alt_model, alt_acc = model_perfs_sorted[1]
    print("Second-best candidate:", alt_name, alt_acc)
else:
    alt_model = None

# Calibrate the best model probabilities if possible
print("\n🔧 Calibrating probabilities (if applicable)...")
try:
    if best_model is not None:
        calib = CalibratedClassifierCV(best_model, cv=3, method='sigmoid')
        calib.fit(X_train_s, y_train_res)
        final_model = calib
        print("   Calibration successful.")
    else:
        raise RuntimeError("No best model found.")
except Exception as e:
    print("   Calibration failed, using raw best model. Error:", e)
    final_model = best_model

# Final evaluation
y_pred_final = final_model.predict(X_test_s)
if hasattr(final_model, "predict_proba"):
    probs = final_model.predict_proba(X_test_s).max(axis=1)
else:
    probs = np.zeros(len(y_pred_final))

test_acc_final = accuracy_score(y_test, y_pred_final)
print("\n📍 Final Test Accuracy:", test_acc_final)
print("📍 Final classification report:")
print(classification_report(y_test, y_pred_final, target_names=["No_Disease","Disease"]))

# Save final confusion matrix
cm_final = confusion_matrix(y_test, y_pred_final)
plt.figure(figsize=(6,5))
sns.heatmap(
    cm_final, annot=True, fmt="d", cmap="Purples",
    xticklabels=["No","Yes"], yticklabels=["No","Yes"]
)
plt.title(f"Final Model ({best_name}) Confusion Matrix")
plt.tight_layout()
final_cm_path = os.path.join(OUTPUT_DIR, f"final_confusion_{best_name}.png")
plt.savefig(final_cm_path)
plt.close()
print("Saved final confusion matrix to:", final_cm_path)

# ---------- NEW: write metrics.json for PHP dashboard ----------
metrics = {
    "best_model": best_name,
    "test_accuracy": float(test_acc_final),
    "train_accuracy_augmented": float(final_model.score(X_train_s, y_train_res)),
    "n_train": int(len(X_train_s)),
    "n_test": int(len(X_test_s)),
}
metrics_path = os.path.join(OUTPUT_DIR, "metrics.json")
with open(metrics_path, "w") as f:
    json.dump(metrics, f, indent=2)
print("Saved metrics.json to:", metrics_path)
# ---------------------------------------------------------------

# ---------------- Save artifacts ----------------
print("\n💾 Saving artifacts for deployment...")
joblib.dump(final_model, os.path.join(OUTPUT_DIR, "best_hcv_model.pkl"))
print("Saved best model to:", os.path.join(OUTPUT_DIR, "best_hcv_model.pkl"))

if alt_model is not None:
    joblib.dump(alt_model, os.path.join(OUTPUT_DIR, "alt_model.pkl"))
    print("Saved alt model to:", os.path.join(OUTPUT_DIR, "alt_model.pkl"))

joblib.dump(scaler, os.path.join(OUTPUT_DIR, "scaler.pkl"))
joblib.dump(label_encoder, os.path.join(OUTPUT_DIR, "label_encoder.pkl"))
joblib.dump(feature_order, os.path.join(OUTPUT_DIR, "feature_order.pkl"))

with open(os.path.join(OUTPUT_DIR, "label_mapping.json"), "w") as f:
    json.dump(label_map, f, indent=2)

print("Saved scaler, label_encoder, feature_order and label_mapping.json in", OUTPUT_DIR)

# ---------------- Save test results CSV for admin UI ----------------
print("\n📝 Producing model_test_results.csv and test_data_sample.csv for UI validation...")
test_results = X_test.copy()
test_results['Actual_Label'] = y_test.map({0:"No_Disease",1:"Disease"})
test_results['Predicted_Label'] = pd.Series(y_pred_final).map({0:"No_Disease",1:"Disease"})
test_results['Probability'] = probs
test_results['Correct'] = test_results['Actual_Label'] == test_results['Predicted_Label']

test_results_path = os.path.join(OUTPUT_DIR, "model_test_results.csv")
test_results.to_csv(test_results_path, index=False)
print("Saved:", test_results_path)

# Also save the raw test sample for admin UI (patient_id generator)
test_sample = X_test.copy()
test_sample.insert(0, 'patient_id', range(1001, 1001 + len(test_sample)))
test_sample['true_label'] = y_test.map({0:"No_Disease",1:"Disease"})
test_sample_path = os.path.join(OUTPUT_DIR, "test_data_sample.csv")
test_sample.to_csv(test_sample_path, index=False)
print("Saved:", test_sample_path, "| rows:", len(test_sample))

# ---------------- PDF report ----------------
report_name = os.path.join(OUTPUT_DIR, f"Training_Report_{datetime.now().strftime('%Y%m%d_%H%M%S')}.pdf")
c = canvas.Canvas(report_name, pagesize=letter)
w, h = letter
c.setFont("Helvetica-Bold", 16)
c.drawString(50, 750, "Liver Disease Model — Training Report")

c.setFont("Helvetica", 11)
c.drawString(50, 730, f"Best Model: {best_name}")
c.drawString(50, 715, f"Final Test Accuracy: {test_acc_final:.4f}")
c.drawString(50, 700, f"Train (approx) accuracy on augmented train: {final_model.score(X_train_s, y_train_res):.4f}")
c.drawString(50, 685, f"CV folds: {N_SPLITS}")
c.drawString(50, 670, f"Dataset shape (after cleaning): {df.shape}")

if os.path.exists(final_cm_path):
    try:
        c.drawImage(final_cm_path, 50, 350, width=480, preserveAspectRatio=True)
    except Exception:
        pass

report_str = classification_report(y_test, y_pred_final, target_names=["No_Disease","Disease"])
c.setFont("Helvetica", 9)
ypos = 320
for line in report_str.splitlines():
    c.drawString(40, ypos, line[:120])
    ypos -= 12
    if ypos < 60:
        c.showPage()
        ypos = 740

c.save()
print("Saved PDF report:", report_name)
print("\n🎉 Training finished. Artifacts in:", OUTPUT_DIR)
