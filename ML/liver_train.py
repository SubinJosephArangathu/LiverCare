#!/usr/bin/env python3
"""
liver_train_fixed.py

Professional HCV multi-class training pipeline.
Fixes:
 - drop 'Unnamed: 0' column if present
 - keep Age + Sex + original lab features
 - stratified train/test split, then SMOTE only on training set
 - RobustScaler for numeric features
 - class-weight-aware models
 - probability calibration
 - save artifacts for Flask/PHP: model, scaler, encoder, feature_order, label mapping
 - export model_test_results.csv and test_data_sample.csv for admin UI validation
 - confusion matrix PNGs and PDF report
"""

import os
import json
import time
from datetime import datetime

import numpy as np
import pandas as pd
import joblib
import matplotlib.pyplot as plt
import seaborn as sns
from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import letter

from sklearn.model_selection import train_test_split, StratifiedKFold, cross_val_score
from sklearn.preprocessing import RobustScaler, LabelEncoder
from sklearn.metrics import classification_report, confusion_matrix, accuracy_score
from sklearn.ensemble import RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.svm import SVC
from sklearn.neural_network import MLPClassifier
from sklearn.calibration import CalibratedClassifierCV

from imblearn.over_sampling import SMOTE

# ---------------- Config ----------------
DATA_FILE = "liver_disease.csv"
RANDOM_STATE = 42
TEST_SIZE = 0.20
N_SPLITS = 5
SMOTE_RANDOM = 42
OUTPUT_DIR = "training_output"
os.makedirs(OUTPUT_DIR, exist_ok=True)

# ---------------- Load ----------------
print("📥 Loading dataset:", DATA_FILE)
df = pd.read_csv(DATA_FILE)
print("    initial shape:", df.shape)

# Drop Unnamed: 0 if it's present (it's not a real feature)
if 'Unnamed: 0' in df.columns:
    print("⚠ Removing 'Unnamed: 0' column (not a real feature).")
    df.drop(columns=['Unnamed: 0'], inplace=True)

# If an index-like column with other name exists, it's left alone. We expect Age, Sex, labs, Category.
# Standardize Sex encoding
if 'Sex' in df.columns:
    df['Sex'] = df['Sex'].replace({'m':'Male','M':'Male','f':'Female','F':'Female'})
    df['Sex'] = df['Sex'].map({'Male': 1, 'Female': 0}).astype(int)

# Drop rows with missing Category
if 'Category' not in df.columns:
    raise ValueError("Dataset must have a 'Category' column with class labels.")
df = df.dropna(subset=['Category'])
print("    after dropping rows w/o Category:", df.shape)

# Fill numeric missing values with median (feature-wise)
numeric_cols = [c for c in df.columns if c != 'Category' and df[c].dtype != 'object']
for c in numeric_cols:
    med = df[c].median()
    df[c] = df[c].fillna(med)

# If Sex is numeric but has NaNs, fill with mode
if 'Sex' in df.columns:
    if df['Sex'].isnull().any():
        df['Sex'].fillna(df['Sex'].mode()[0], inplace=True)

# Print label distribution
print("\n📊 Class distribution (original):")
print(df['Category'].value_counts())

# Encode labels using LabelEncoder but also preserve mapping to human labels
label_encoder = LabelEncoder()
df['label'] = label_encoder.fit_transform(df['Category'])
label_map = {int(v): k for k, v in zip(label_encoder.classes_, label_encoder.transform(label_encoder.classes_))}
print("\n🔤 Label mapping (encoded -> name):", label_map)

# Build X, y preserving original feature columns (drop only Category and label)
X = df.drop(columns=['Category','label'])
y = df['label']

print("\n📋 Features used (columns):")
print(list(X.columns))

# Save feature order used for prediction (so Flask/PHP must send these fields in this order)
feature_order = list(X.columns)
joblib.dump(feature_order, os.path.join(OUTPUT_DIR, "feature_order.pkl"))
with open(os.path.join(OUTPUT_DIR, "feature_order.json"), "w") as f:
    json.dump(feature_order, f, indent=2)
print("✅ feature_order saved to", os.path.join(OUTPUT_DIR, "feature_order.json"))

# ---------------- Train / Test split ----------------
print("\n🔀 Doing stratified train/test split (test_size=", TEST_SIZE, ")")
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=TEST_SIZE,
                                                    stratify=y, random_state=RANDOM_STATE)
print("   Train:", X_train.shape, "Test:", X_test.shape)
print("   Train class counts (before SMOTE):")
print(pd.Series(y_train).map(lambda x: label_map[x]).value_counts())

# ---------------- SMOTE on TRAIN only ----------------
print("\n✨ Applying SMOTE on training set only...")
smote = SMOTE(random_state=SMOTE_RANDOM)
X_train_res, y_train_res = smote.fit_resample(X_train, y_train)
print("   After SMOTE class counts:")
print(pd.Series(y_train_res).map(lambda x: label_map[x]).value_counts())

# ---------------- Scaling ----------------
print("\n⚖ Fitting RobustScaler on training set...")
scaler = RobustScaler()
X_train_s = scaler.fit_transform(X_train_res)
X_test_s = scaler.transform(X_test)

joblib.dump(scaler, os.path.join(OUTPUT_DIR, "scaler.pkl"))
joblib.dump(label_encoder, os.path.join(OUTPUT_DIR, "label_encoder.pkl"))
with open(os.path.join(OUTPUT_DIR, "label_mapping.json"), "w") as f:
    json.dump(label_map, f, indent=2)
print("✅ Saved scaler, label_encoder and label_mapping.json in", OUTPUT_DIR)

# ---------------- Models ----------------
print("\n🚀 Preparing models (class-weight aware where supported)...")
models = {
    "RandomForest": RandomForestClassifier(n_estimators=300, random_state=RANDOM_STATE, class_weight='balanced'),
    "LogisticRegression": LogisticRegression(max_iter=3000, class_weight='balanced', solver='liblinear'),
    "SVM": SVC(probability=True, class_weight='balanced'),
    "MLP": MLPClassifier(hidden_layer_sizes=(128,64), max_iter=1500, random_state=RANDOM_STATE)
}

skf = StratifiedKFold(n_splits=N_SPLITS, shuffle=True, random_state=RANDOM_STATE)

best_model = None
best_name = None
best_test_acc = -1.0

for idx, (name, model) in enumerate(models.items(), start=1):
    print(f"\n[{idx}/{len(models)}] Training {name}")
    fold_scores = []
    fold_no = 1
    for train_idx, val_idx in skf.split(X_train_s, y_train_res):
        print(f"   • Fold {fold_no} ...", end=" ")
        t0 = time.time()
        model.fit(X_train_s[train_idx], y_train_res.iloc[train_idx])
        score = model.score(X_train_s[val_idx], y_train_res.iloc[val_idx])
        t1 = time.time()
        fold_scores.append(score)
        print(f"Acc: {score:.4f} | time: {t1-t0:.2f}s")
        fold_no += 1

    mean_cv = np.mean(fold_scores)
    print(f"   -> CV mean accuracy: {mean_cv:.4f}")

    # Evaluate on test
    y_pred = model.predict(X_test_s)
    acc_test = accuracy_score(y_test, y_pred)
    print(f"   -> Test accuracy: {acc_test:.4f}")
    print("   Classification report (test set):")
    print(classification_report(y_test, y_pred, target_names=label_encoder.classes_))

    # Save confusion matrix
    cm = confusion_matrix(y_test, y_pred)
    plt.figure(figsize=(6,5))
    sns.heatmap(cm, annot=True, fmt="d", cmap="Blues",
                xticklabels=label_encoder.classes_, yticklabels=label_encoder.classes_)
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

print("\n🏆 Best model:", best_name, "| Test Accuracy:", best_test_acc)

# ---------------- Calibrate probabilities ----------------
print("\n🔧 Calibrating probabilities (CalibratedClassifierCV with sigmoid)...")
try:
    calib = CalibratedClassifierCV(best_model, cv=3, method='sigmoid')
    calib.fit(X_train_s, y_train_res)
    final_model = calib
    print("   Calibration successful.")
except Exception as e:
    print("   Calibration failed, using raw best model. Error:", e)
    final_model = best_model

# ---------------- Final evaluation ----------------
y_pred_final = final_model.predict(X_test_s)
if hasattr(final_model, "predict_proba"):
    probs = final_model.predict_proba(X_test_s).max(axis=1)
else:
    probs = np.zeros(len(y_pred_final))

test_acc_final = accuracy_score(y_test, y_pred_final)
print("\n📍 Final Test Accuracy:", test_acc_final)
print("📍 Classification report (final):")
print(classification_report(y_test, y_pred_final, target_names=label_encoder.classes_))

# Save final confusion matrix
cm_final = confusion_matrix(y_test, y_pred_final)
plt.figure(figsize=(6,5))
sns.heatmap(cm_final, annot=True, fmt="d", cmap="Purples",
            xticklabels=label_encoder.classes_, yticklabels=label_encoder.classes_)
plt.title(f"Final Model ({best_name}) Confusion Matrix")
plt.tight_layout()
final_cm_path = os.path.join(OUTPUT_DIR, f"final_confusion_{best_name}.png")
plt.savefig(final_cm_path)
plt.close()
print("Saved final confusion matrix to:", final_cm_path)

# ---------------- Save artifacts ----------------
print("\n💾 Saving artifacts for deployment...")
joblib.dump(final_model, os.path.join(OUTPUT_DIR, "best_hcv_model.pkl"))
joblib.dump(scaler, os.path.join(OUTPUT_DIR, "scaler.pkl"))
joblib.dump(label_encoder, os.path.join(OUTPUT_DIR, "label_encoder.pkl"))
joblib.dump(feature_order, os.path.join(OUTPUT_DIR, "feature_order.pkl"))

with open(os.path.join(OUTPUT_DIR, "label_mapping.json"), "w") as f:
    json.dump(label_map, f, indent=2)

print("Saved: best_hcv_model.pkl, scaler.pkl, label_encoder.pkl, feature_order.pkl, label_mapping.json")

# ---------------- Save test results CSV for admin UI ----------------
print("\n📝 Producing model_test_results.csv and test_data_sample.csv for UI validation...")
test_results = X_test.copy()
test_results['Actual_Label'] = label_encoder.inverse_transform(y_test)
test_results['Predicted_Label'] = label_encoder.inverse_transform(y_pred_final)
test_results['Probability'] = probs
test_results['Correct'] = test_results['Actual_Label'] == test_results['Predicted_Label']

test_results_path = os.path.join(OUTPUT_DIR, "model_test_results.csv")
test_results.to_csv(test_results_path, index=False)
print("Saved:", test_results_path)

# Also save the raw test sample for admin UI (patient_id generator)
test_sample = X_test.copy()
test_sample.insert(0, 'patient_id', range(1001, 1001 + len(test_sample)))
test_sample['true_label'] = label_encoder.inverse_transform(y_test)
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
    c.drawImage(final_cm_path, 50, 350, width=480, preserveAspectRatio=True)

# Add small classification report text
report_str = classification_report(y_test, y_pred_final, target_names=label_encoder.classes_)
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
