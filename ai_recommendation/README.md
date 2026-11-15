# AI Recommendation System

A hybrid recommendation system for EventGenie that provides personalized recommendations for venues, themes, and food options based on user preferences and event requirements.

## Overview

This system uses a hybrid approach combining:
- **Knowledge-based filtering**: Removes incompatible venues based on hard constraints (capacity, event type, gender restrictions)
- **Content-based scoring**: Matches venues, themes, and food based on features (food preferences, ratings, user preferences)
- **Collaborative filtering (KNN)**: Finds similar users and recommends items they liked
- **Hybrid combination**: Combines all approaches for optimal recommendations

## Features

- **Venue Recommendations**: Recommends venues based on capacity, event type, gender restrictions, food preferences, and user ratings
- **Theme Recommendations**: Recommends event themes based on event type, cultural fit, user style preferences, colors, and season
- **Food Recommendations**: Recommends food options based on event type, user preferences, and popularity
- **Evaluation Metrics**: Calculates MAP (Mean Average Precision) and NDCG@K for accuracy evaluation
- **RESTful API**: Flask-based API for easy integration
- **Database Integration**: Stores recommendations in MySQL for tracking and analysis

## Installation

### Prerequisites

- Python 3.7+
- MySQL database
- pip (Python package manager)

### Setup

1. **Install dependencies**:
   ```bash
   pip install -r requirements.txt
   ```

2. **Configure database**:
   Edit `config.py` with your database credentials:
   ```python
   DB_CONFIG = {
       "host": "localhost",
       "user": "your_username",
       "password": "your_password",
       "database": "eventgenie_mvp"
   }
   ```

3. **Database tables required**:
   - `venues` - Venue information
   - `event_themes` - Theme information
   - `food_options` - Food options
   - `users` - User preferences
   - `events` - Event data
   - `recommendations` - Stored recommendations
   - `feedback` - User ratings/feedback

## File Structure

```
ai_recommendation/
├── app.py                      # Flask API application
├── config.py                   # Database configuration
├── recommender.py              # Core recommendation logic
├── evaluation.py               # Evaluation metrics calculation
├── generate_accuracy_report.py  # Generate accuracy reports
├── requirements.txt            # Python dependencies
└── README.md                   # This file
```

## API Endpoints

### 1. Get Recommendations

**Endpoint**: `POST /recommend`

**Request Body**:
```json
{
    "user_id": 1,
    "event_type": "Wedding",
    "guest_count": 100,
    "gender": "Both",
    "food_pref": "Arabic"  // Optional, auto-filled from user profile if not provided
}
```

**Response**:
```json
{
    "recommendations": {
        "venues": [
            {
                "venue_id": 5,
                "name": "Grand Ballroom",
                "score": 0.85
            }
        ],
        "themes": [
            {
                "theme_id": 2,
                "name": "Elegant Classic",
                "score": 0.78
            }
        ],
        "food": [
            {
                "food_id": 1,
                "name": "Arabic Buffet",
                "score": 0.92
            }
        ]
    },
    "rec_id": 123
}
```

### 2. Evaluate System Performance

**Endpoint**: `GET /evaluate?k=3,5,10`

**Response**:
```json
{
    "metrics": {
        "venues": {
            "map": 0.6543,
            "ndcg@3": 0.6234,
            "ndcg@5": 0.7123,
            "ndcg@10": 0.7891
        },
        "themes": { ... },
        "food": { ... }
    },
    "overall": {
        "total_recommendations": 50,
        "total_events": 30,
        "matched_pairs": 25,
        "match_rate": 0.8333
    }
}
```

### 3. Evaluate Specific Item Type

**Endpoint**: `GET /evaluate/venues?k=3,5,10`
**Endpoint**: `GET /evaluate/themes?k=3,5,10`
**Endpoint**: `GET /evaluate/food?k=3,5,10`

## Running the Application

### Start the Flask Server

```bash
python app.py
```

The server will start on `http://localhost:5000` (default Flask port).

### Test the API

```bash
# Get recommendations
curl -X POST http://localhost:5000/recommend \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "event_type": "Wedding",
    "guest_count": 100,
    "gender": "Both"
  }'

# Get evaluation metrics
curl http://localhost:5000/evaluate?k=3,5,10
```

## Scripts

### Generate Accuracy Report

Generate a comprehensive accuracy report with MAP and NDCG metrics:

```bash
# Default K values (3, 5, 10)
python generate_accuracy_report.py

# Custom K values
python generate_accuracy_report.py 3,5,10

# Save to JSON file
python generate_accuracy_report.py 3,5,10 accuracy_report.json
```

## How It Works

### Recommendation Process

1. **Input Processing**: Receives event requirements (event type, guest count, gender, food preference)

2. **User Preferences**: Automatically fetches user preferences from profile if available

3. **Knowledge-Based Filtering**: 
   - Filters venues by event type compatibility
   - Filters by capacity constraints (min/max)
   - Filters by gender restrictions

4. **Content-Based Scoring**:
   - Matches food preferences
   - Considers user style/color preferences for themes
   - Incorporates ratings and popularity

5. **Collaborative Filtering**:
   - Uses KNN to find similar users
   - Recommends items liked by similar users

6. **Hybrid Scoring**:
   - Combines all scores with weighted approach
   - Normalizes scores to 0-1 range
   - Returns top-k recommendations

### Evaluation Metrics

- **MAP (Mean Average Precision)**: Measures ranking quality across all recommendations
- **NDCG@K (Normalized Discounted Cumulative Gain)**: Measures ranking quality with position discounting

## Configuration

### Database Configuration

Edit `config.py`:
```python
DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "eventgenie_mvp"
}
```

### Model Version

The system uses model version `v4.0`. Recommendations are stored with this version for tracking and comparison.

## Dependencies

- **Flask 2.3.4**: Web framework for API
- **mysql-connector-python 8.1.0**: MySQL database connector
- **pandas 2.1.1**: Data manipulation
- **scikit-learn 1.3.2**: Machine learning (KNN)
- **numpy 1.26.4**: Numerical computing

## Troubleshooting

### Database Connection Issues

- Verify database credentials in `config.py`
- Ensure MySQL server is running
- Check database name matches your setup

### No Recommendations Returned

- Verify venues/themes/food exist in database
- Check that venues match event type and capacity requirements
- Ensure user_id exists in users table

### Low Accuracy Scores

- Normal ranges: MAP 0.3-0.7, NDCG@K 0.4-0.8
- Improve by collecting more user feedback
- Ensure data quality (complete venue/theme information)
- Fine-tune scoring weights in `recommender.py`

### AUTO_INCREMENT Errors

If you see AUTO_INCREMENT errors when storing recommendations:
- The `rec_id` column may need to be changed to `BIGINT`
- Recommendations will still be returned even if storage fails
- Check database schema for `recommendations` table

## Best Practices

1. **Regular Evaluation**: Run accuracy reports periodically to track performance
2. **Data Quality**: Ensure complete venue/theme information in database
3. **User Feedback**: Collect user ratings to improve collaborative filtering
4. **Monitor Metrics**: Track MAP and NDCG@K over time
5. **A/B Testing**: Compare different recommendation strategies

## License

Part of the EventGenie MVP project.

