from flask import Flask, jsonify
from flask_cors import CORS
import pandas as pd
import numpy as np
import requests
import json
from datetime import datetime, timedelta
import warnings
import logging

# Model libraries
from sklearn.ensemble import RandomForestRegressor, GradientBoostingRegressor
from sklearn.linear_model import LinearRegression
from sklearn.metrics import mean_absolute_error
from sklearn.model_selection import TimeSeriesSplit, cross_val_score
import xgboost as xgb

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

warnings.filterwarnings('ignore')

app = Flask(__name__)
CORS(app)

class IncidentPredictor:
    def __init__(self):
        self.df = None
        self.models = {}
        self.best_model = None
        self.features_df = None
        self.predictions = None
        self.analysis_results = {}
        
        self.initialize_data()
        self.preprocess_data()
        self.train_models()
        self.generate_predictions()
        self.generate_analysis()
    
    def fetch_incident_data(self):
        """Fetch incident data from the API endpoint"""
        api_url = "http://localhost/resq/prediction.php"
        
        try:
            logger.info("📡 Fetching data from API...")
            response = requests.get(api_url, timeout=30)
            response.raise_for_status()
            
            data = response.json()
            
            if data.get('success'):
                logger.info(f"✅ Successfully fetched {len(data['data'])} records from API")
                return pd.DataFrame(data['data'])
            else:
                logger.error("❌ API returned unsuccessful response")
                return self.get_sample_data()
                
        except requests.exceptions.RequestException as e:
            logger.error(f"❌ Error fetching data from API: {e}")
            return self.get_sample_data()
        except json.JSONDecodeError as e:
            logger.error(f"❌ Error parsing JSON response: {e}")
            return self.get_sample_data()
    
    def get_sample_data(self):
        """Generate sample data for development when API is unavailable"""
        logger.info("🔄 Using sample data for development")
        
        dates = pd.date_range(start='2023-01-01', end='2024-01-01', freq='D')
        incident_types = ['Fire', 'Medical', 'Traffic', 'Flood', 'Earthquake']
        severity_levels = ['low', 'medium', 'high']
        barangays = list(range(1, 21))
        
        sample_data = []
        for i in range(200):
            sample_data.append({
                'incident_id': i + 1,
                'incident_type': np.random.choice(incident_types),
                'severity_level': np.random.choice(severity_levels),
                'baranggay_id': np.random.choice(barangays),
                'status': np.random.choice(['pending', 'ongoing', 'resolved']),
                'created_at': np.random.choice(dates),
                'updated_at': np.random.choice(dates)
            })
        
        return pd.DataFrame(sample_data)
    
    def initialize_data(self):
        """Initialize and load data"""
        self.df = self.fetch_incident_data()
        if self.df is None:
            raise Exception("Failed to load incident data")
    
    def preprocess_data(self):
        """Preprocess the data for modeling"""
        # Convert datetime columns
        self.df['created_at'] = pd.to_datetime(self.df['created_at'])
        self.df['updated_at'] = pd.to_datetime(self.df['updated_at'])
        
        # Extract temporal features
        self.df['year'] = self.df['created_at'].dt.year
        self.df['month'] = self.df['created_at'].dt.month
        self.df['day_of_week'] = self.df['created_at'].dt.dayofweek
        self.df['is_weekend'] = self.df['day_of_week'].isin([5, 6]).astype(int)
        self.df['quarter'] = self.df['created_at'].dt.quarter
        
        # Map categorical variables to numerical
        severity_map = {'low': 0, 'medium': 1, 'high': 2}
        self.df['severity_numeric'] = self.df['severity_level'].map(severity_map)
        status_map = {'pending': 0, 'ongoing': 1, 'resolved': 2}
        self.df['status_numeric'] = self.df['status'].map(status_map)
        
        # Create panel data for time series
        self.create_panel_data()
    
    def create_panel_data(self):
        """Create panel data for time series analysis"""
        all_barangays = self.df['baranggay_id'].unique()
        start_date = self.df['created_at'].min().replace(day=1)
        end_date = self.df['created_at'].max() + timedelta(days=30)
        date_range = pd.date_range(start=start_date, end=end_date, freq='M')
        
        # Create panel data structure
        panel_data = []
        for barangay in all_barangays:
            for date in date_range:
                panel_data.append({
                    'baranggay_id': barangay,
                    'year_month': date.strftime('%Y-%m'),
                    'date': date,
                    'year': date.year,
                    'month': date.month,
                    'quarter': date.quarter
                })
        
        panel_df = pd.DataFrame(panel_data)
        
        # Aggregate monthly incidents
        self.df['year_month'] = self.df['created_at'].dt.strftime('%Y-%m')
        monthly_incidents = self.df.groupby(['baranggay_id', 'year_month']).agg({
            'incident_id': 'count',
            'severity_numeric': ['mean', 'max', 'sum'],
            'incident_type': 'nunique',
            'status_numeric': 'mean'
        }).reset_index()
        
        # Flatten column names
        monthly_incidents.columns = ['baranggay_id', 'year_month', 'monthly_incidents', 
                                   'avg_severity', 'max_severity', 'total_severity',
                                   'unique_incident_types', 'avg_status']
        
        # Merge with panel data
        self.features_df = pd.merge(panel_df, monthly_incidents, 
                                  on=['baranggay_id', 'year_month'], 
                                  how='left').fillna(0)
        
        # Create target variable
        self.features_df = self.features_df.sort_values(['baranggay_id', 'date'])
        self.features_df['target_incidents'] = self.features_df.groupby('baranggay_id')['monthly_incidents'].shift(-1)
        self.features_df = self.features_df.dropna(subset=['target_incidents'])
    
    def train_models(self):
        """Train machine learning models"""
        # Prepare features
        exclude_columns = ['baranggay_id', 'date', 'year_month', 'target_incidents']
        feature_columns = [col for col in self.features_df.columns 
                         if col not in exclude_columns and 
                         self.features_df[col].dtype in [np.int64, np.float64]]
        
        X = self.features_df[feature_columns]
        y = self.features_df['target_incidents']
        
        # Initialize models
        self.models = {
            'RandomForest': RandomForestRegressor(n_estimators=100, random_state=42),
            'XGBoost': xgb.XGBRegressor(n_estimators=100, random_state=42),
            'LinearRegression': LinearRegression(),
            'GradientBoosting': GradientBoostingRegressor(n_estimators=100, random_state=42)
        }
        
        # Train and evaluate models
        tscv = TimeSeriesSplit(n_splits=3)
        best_score = float('inf')
        self.best_model = None
        
        for model_name, model in self.models.items():
            # Cross-validation
            scores = cross_val_score(model, X, y, cv=tscv, scoring='neg_mean_absolute_error')
            mae_score = -scores.mean()
            
            # Train on full data
            model.fit(X, y)
            self.models[model_name] = model
            
            # Track best model
            if mae_score < best_score:
                best_score = mae_score
                self.best_model = model
                self.best_model_name = model_name
        
        logger.info(f"✅ Models trained. Best model: {self.best_model_name} (MAE: {best_score:.4f})")
    
    def generate_predictions(self):
        """Generate predictions for all barangays"""
        # Prepare features for prediction
        exclude_columns = ['baranggay_id', 'date', 'year_month', 'target_incidents']
        feature_columns = [col for col in self.features_df.columns 
                         if col not in exclude_columns and 
                         self.features_df[col].dtype in [np.int64, np.float64]]
        
        # Get latest data for each barangay
        latest_data = self.features_df.sort_values('date').groupby('baranggay_id').tail(1).copy()
        X_pred = latest_data[feature_columns]
        
        # Make predictions
        predictions = self.best_model.predict(X_pred)
        
        # Create results
        self.predictions = pd.DataFrame({
            'barangay_id': latest_data['baranggay_id'],
            'expected_incidents': predictions,
            'current_incidents': latest_data['monthly_incidents'],
            'trend': predictions - latest_data['monthly_incidents']
        })
        
        # Calculate risk scores
        min_incidents = self.predictions['expected_incidents'].min()
        max_incidents = self.predictions['expected_incidents'].max()
        
        if max_incidents > min_incidents:
            self.predictions['risk_score'] = (self.predictions['expected_incidents'] - min_incidents) / (max_incidents - min_incidents)
        else:
            self.predictions['risk_score'] = 0.5  # Default if all values are same
        
        # Assign risk levels
        self.predictions['risk_level'] = pd.cut(self.predictions['risk_score'], 
                                              bins=[0, 0.3, 0.7, 1], 
                                              labels=['LOW', 'MEDIUM', 'HIGH'],
                                              include_lowest=True)
        
        self.predictions = self.predictions.sort_values('risk_score', ascending=False)
        logger.info(f"✅ Predictions generated for {len(self.predictions)} barangays")
    
    def generate_analysis(self):
        """Generate analysis and insights"""
        # Basic statistics
        self.analysis_results['total_incidents'] = len(self.df)
        self.analysis_results['total_barangays'] = self.df['baranggay_id'].nunique()
        self.analysis_results['date_range'] = {
            'start': self.df['created_at'].min().strftime('%Y-%m-%d'),
            'end': self.df['created_at'].max().strftime('%Y-%m-%d')
        }
        
        # Incident type distribution
        incident_counts = self.df['incident_type'].value_counts().to_dict()
        self.analysis_results['incident_types'] = incident_counts
        
        # Severity distribution
        severity_counts = self.df['severity_level'].value_counts().to_dict()
        self.analysis_results['severity_levels'] = severity_counts
        
        # Monthly trends
        monthly_trend = self.df.groupby('month').size()
        self.analysis_results['monthly_trend'] = monthly_trend.to_dict()
        
        # Top barangays
        barangay_activity = self.df['baranggay_id'].value_counts().head(10).to_dict()
        self.analysis_results['top_barangays'] = {f"Barangay {k}": int(v) for k, v in barangay_activity.items()}
        
        # Model performance
        self.analysis_results['best_model'] = self.best_model_name
        self.analysis_results['predictions_count'] = len(self.predictions)
        
        # Risk distribution
        risk_counts = self.predictions['risk_level'].value_counts().to_dict()
        self.analysis_results['risk_distribution'] = risk_counts
        
        # High risk barangays
        high_risk = self.predictions[self.predictions['risk_level'] == 'HIGH']
        if len(high_risk) > 0:
            self.analysis_results['high_risk_barangays'] = [int(x) for x in high_risk['barangay_id'].tolist()]
        else:
            self.analysis_results['high_risk_barangays'] = []
    
    def get_predictions(self):
        """Get all predictions in JSON format"""
        if self.predictions is None:
            return []
        
        predictions_list = []
        for _, row in self.predictions.iterrows():
            predictions_list.append({
                'barangay_id': int(row['barangay_id']),
                'expected_incidents': float(row['expected_incidents']),
                'current_incidents': float(row['current_incidents']),
                'trend': float(row['trend']),
                'risk_score': float(row['risk_score']),
                'risk_level': str(row['risk_level'])
            })
        
        return predictions_list
    
    def get_barangay_prediction(self, barangay_id):
        """Get prediction for specific barangay"""
        if self.predictions is None:
            return None
        
        prediction = self.predictions[self.predictions['barangay_id'] == barangay_id]
        if len(prediction) == 0:
            return None
        
        row = prediction.iloc[0]
        return {
            'barangay_id': int(row['barangay_id']),
            'expected_incidents': float(row['expected_incidents']),
            'current_incidents': float(row['current_incidents']),
            'trend': float(row['trend']),
            'risk_score': float(row['risk_score']),
            'risk_level': str(row['risk_level'])
        }
    
    def get_analysis(self):
        """Get analysis results"""
        return self.analysis_results

# Global predictor instance
predictor = None

def initialize_predictor():
    """Initialize the predictor"""
    global predictor
    try:
        predictor = IncidentPredictor()
        logger.info("✅ Incident predictor initialized successfully")
        return True
    except Exception as e:
        logger.error(f"❌ Failed to initialize predictor: {e}")
        return False

@app.route('/')
def home():
    """Home endpoint"""
    return jsonify({
        "message": "Incident Prediction API",
        "status": "running",
        "timestamp": datetime.now().isoformat(),
        "endpoints": {
            "/predict": "GET predictions for all barangays",
            "/predict/<barangay_id>": "GET prediction for specific barangay",
            "/analysis": "GET data analysis and insights",
            "/health": "GET API health status",
            "/refresh": "Refresh data and retrain models",
            "/status": "Get system status"
        }
    })

@app.route('/health')
def health():
    """Health check endpoint"""
    global predictor
    status = "healthy" if predictor is not None else "initializing"
    return jsonify({
        "status": status,
        "timestamp": datetime.now().isoformat()
    })

@app.route('/predict')
def get_predictions():
    """Get predictions for all barangays"""
    global predictor
    try:
        if predictor is None:
            if not initialize_predictor():
                return jsonify({"error": "Failed to initialize predictor"}), 500
        
        predictions = predictor.get_predictions()
        return jsonify({
            "success": True,
            "data": predictions,
            "timestamp": datetime.now().isoformat(),
            "total_predictions": len(predictions),
            "best_model": predictor.best_model_name
        })
    except Exception as e:
        logger.error(f"Prediction error: {e}")
        return jsonify({"error": str(e)}), 500

@app.route('/predict/<int:barangay_id>')
def get_prediction(barangay_id):
    """Get prediction for specific barangay"""
    global predictor
    try:
        if predictor is None:
            if not initialize_predictor():
                return jsonify({"error": "Failed to initialize predictor"}), 500
        
        prediction = predictor.get_barangay_prediction(barangay_id)
        if prediction:
            return jsonify({
                "success": True,
                "data": prediction,
                "timestamp": datetime.now().isoformat()
            })
        else:
            return jsonify({"error": "Barangay not found"}), 404
    except Exception as e:
        logger.error(f"Prediction error for barangay {barangay_id}: {e}")
        return jsonify({"error": str(e)}), 500

@app.route('/analysis')
def get_analysis():
    """Get data analysis and insights"""
    global predictor
    try:
        if predictor is None:
            if not initialize_predictor():
                return jsonify({"error": "Failed to initialize predictor"}), 500
        
        analysis = predictor.get_analysis()
        return jsonify({
            "success": True,
            "data": analysis,
            "timestamp": datetime.now().isoformat()
        })
    except Exception as e:
        logger.error(f"Analysis error: {e}")
        return jsonify({"error": str(e)}), 500

@app.route('/refresh')
def refresh_data():
    """Refresh data and retrain models"""
    global predictor
    try:
        predictor = IncidentPredictor()
        return jsonify({
            "success": True,
            "message": "Data refreshed and models retrained",
            "timestamp": datetime.now().isoformat(),
            "best_model": predictor.best_model_name,
            "total_incidents": len(predictor.df),
            "total_predictions": len(predictor.predictions)
        })
    except Exception as e:
        logger.error(f"Refresh error: {e}")
        return jsonify({"error": str(e)}), 500

@app.route('/status')
def get_status():
    """Get current system status"""
    global predictor
    try:
        if predictor is None:
            return jsonify({
                "status": "not_initialized",
                "timestamp": datetime.now().isoformat()
            })
        
        return jsonify({
            "status": "initialized",
            "best_model": predictor.best_model_name,
            "total_incidents": len(predictor.df),
            "total_barangays": predictor.df['baranggay_id'].nunique(),
            "predictions_available": len(predictor.predictions),
            "last_updated": datetime.now().isoformat()
        })
    except Exception as e:
        logger.error(f"Status error: {e}")
        return jsonify({"error": str(e)}), 500

# Initialize predictor when the app starts
print("🚀 Starting Incident Prediction API Server...")
print("📊 Initializing predictor...")

if initialize_predictor():
    print("✅ Predictor initialized successfully!")
else:
    print("⚠️  Predictor initialization failed, will initialize on first request")

print("\n📊 Available Endpoints:")
print("   http://localhost:5000/ - API Home")
print("   http://localhost:5000/predict - Get all predictions")
print("   http://localhost:5000/predict/1 - Get prediction for Barangay 1")
print("   http://localhost:5000/analysis - Get data analysis")
print("   http://localhost:5000/health - Health check")
print("   http://localhost:5000/refresh - Refresh data")
print("   http://localhost:5000/status - System status")
print("\n⚡ Server starting on http://localhost:5000")

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)