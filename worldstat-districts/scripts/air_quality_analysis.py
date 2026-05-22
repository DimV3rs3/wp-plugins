#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Machine Learning Analysis for NYC Air Quality Data
"""

import sys
import json
import numpy as np
import pandas as pd
from sklearn.cluster import KMeans
from sklearn.ensemble import RandomForestClassifier, RandomForestRegressor
from sklearn.naive_bayes import GaussianNB
from sklearn.linear_model import LinearRegression
from sklearn.preprocessing import StandardScaler
from sklearn.model_selection import train_test_split
import warnings
warnings.filterwarnings('ignore')

def main():
    if len(sys.argv) < 3:
        print("Usage: python air_quality_analysis.py <input_file> <output_file>")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_file = sys.argv[2]
    
    # Load data
    with open(input_file, 'r') as f:
        data = json.load(f)
    
    district_ids = data['districts']
    features = np.array(data['features'])
    feature_names = data['feature_names']
    
    # Create DataFrame
    df = pd.DataFrame(features, columns=feature_names)
    
    # Standardize features
    scaler = StandardScaler()
    X_scaled = scaler.fit_transform(features)
    
    results = {
        'districts': [],
        'clusters': [],
        'classifications': [],
        'predicted_prices': []
    }
    
    # 1. K-MEANS CLUSTERING
    print("Running K-Means Clustering...")
    kmeans = KMeans(n_clusters=3, random_state=42, n_init=10)
    clusters = kmeans.fit_predict(X_scaled)
    
    # 2. CLASSIFICATION (Random Forest)
    print("Running Random Forest Classification...")
    
    # Create target labels based on air quality
    # Good: ozone < 30, no2 < 10, pm25 < 8
    # Moderate: ozone 30-35, no2 10-15, pm25 8-10
    # Poor: ozone > 35, no2 > 15, pm25 > 10
    
    labels = []
    for i, row in enumerate(features):
        ozone, no2, pm25 = row
        if ozone < 30 and no2 < 10 and pm25 < 8:
            labels.append('Good')
        elif ozone > 35 or no2 > 15 or pm25 > 10:
            labels.append('Poor')
        else:
            labels.append('Moderate')
    
    # Train classifier
    rf_classifier = RandomForestClassifier(n_estimators=100, random_state=42)
    rf_classifier.fit(X_scaled, labels)
    classifications = rf_classifier.predict(X_scaled)
    
    # 3. NAIVE BAYES CLASSIFICATION
    print("Running Naive Bayes Classification...")
    nb_classifier = GaussianNB()
    nb_classifier.fit(X_scaled, labels)
    nb_predictions = nb_classifier.predict(X_scaled)
    
    # 4. REGRESSION (Linear and Random Forest)
    print("Running Regression Analysis...")
    
    # Create target variable (air quality index)
    target = []
    for i, row in enumerate(features):
        ozone, no2, pm25 = row
        aqi = (ozone / 50 * 0.3 + no2 / 40 * 0.3 + pm25 / 25 * 0.4) * 100
        target.append(min(100, max(0, aqi)))
    
    # Linear Regression
    lr_model = LinearRegression()
    lr_model.fit(X_scaled, target)
    lr_predictions = lr_model.predict(X_scaled)
    
    # Random Forest Regression
    rf_regressor = RandomForestRegressor(n_estimators=100, random_state=42)
    rf_regressor.fit(X_scaled, target)
    rf_predictions = rf_regressor.predict(X_scaled)
    
    # 5. Calculate comfort/safety scores based on ML results
    print("Calculating Comfort & Safety Scores...")
    
    comfort_scores = []
    safety_scores = []
    functionality_scores = []
    
    for i, row in enumerate(features):
        ozone, no2, pm25 = row
        
        # Comfort score (inverse of pollution)
        ozone_norm = max(0, min(100, (30 - ozone) / 30 * 100))
        no2_norm = max(0, min(100, (20 - no2) / 20 * 100))
        pm25_norm = max(0, min(100, (15 - pm25) / 15 * 100))
        
        comfort = (ozone_norm + no2_norm + pm25_norm) / 3
        safety = 100 - pm25_norm * 0.5
        functionality = 60 + no2_norm * 0.4
        
        comfort_scores.append(round(comfort, 1))
        safety_scores.append(round(safety, 1))
        functionality_scores.append(round(functionality, 1))
    
    # Prepare results
    for i, district_id in enumerate(district_ids):
        results['districts'].append(district_id)
        results['clusters'].append(int(clusters[i]))
        results['classifications'].append(classifications[i])
        results['predicted_prices'].append(round(rf_predictions[i], 2))
    
    # Save results
    with open(output_file, 'w') as f:
        json.dump({
            'districts': results['districts'],
            'clusters': results['clusters'],
            'classifications': results['classifications'],
            'predicted_prices': results['predicted_prices'],
            'comfort_scores': comfort_scores,
            'safety_scores': safety_scores,
            'functionality_scores': functionality_scores,
            'statistics': {
                'cluster_centers': kmeans.cluster_centers_.tolist(),
                'feature_importance': rf_classifier.feature_importances_.tolist(),
                'regression_coefficients': lr_model.coef_.tolist(),
                'mean_comfort': np.mean(comfort_scores),
                'mean_safety': np.mean(safety_scores),
                'mean_functionality': np.mean(functionality_scores),
            }
        }, f, indent=2)
    
    print(f"Analysis complete! Results saved to {output_file}")

if __name__ == '__main__':
    main()