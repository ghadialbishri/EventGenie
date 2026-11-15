from flask import Flask, request, jsonify
from recommender import (
    recommend_venues,
    recommend_themes,
    recommend_food,
    fetch_user_preferences,
)
from evaluation import evaluate_recommendations
import mysql.connector
import json
from config import DB_CONFIG

app = Flask(__name__)


@app.route("/recommend", methods=["POST"])
def recommend():
    data = request.get_json()
    # Required fields
    required_keys = ["user_id", "event_type", "guest_count", "gender"]
    for key in required_keys:
        if key not in data:
            return jsonify({"error": f"Missing {key}"}), 400

    # Auto-fill food preference from user profile if not provided
    try:
        user_prefs = fetch_user_preferences(data["user_id"])
    except Exception:
        user_prefs = None

    if user_prefs and user_prefs.get("food") and not data.get("food_pref"):
        data["food_pref"] = user_prefs["food"]

    try:
        venues = recommend_venues(data)
        themes = recommend_themes(data)
        food = recommend_food(data)
    except Exception as e:
        print(f"Error generating recommendations: {str(e)}")
        import traceback
        traceback.print_exc()
        return jsonify({"error": f"Error generating recommendations: {str(e)}"}), 500

    recommendations = {"venues": venues, "themes": themes, "food": food}

    # Store recommendation (optional - continue even if storage fails)
    # Note: rec_id is AUTO_INCREMENT, so we don't specify it - let the database generate it
    rec_id = None
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        cursor.execute(
            """
            INSERT INTO recommendations (user_id, session_id, input_json, items_json, model_version, status)
            VALUES (%s,%s,%s,%s,%s,%s)""",
            (
                data["user_id"],
                None,
                json.dumps(data),
                json.dumps(recommendations),
                "v4.0",
                "active",
            ),
        )
        conn.commit()
        # Get the auto-generated rec_id
        rec_id = cursor.lastrowid
        cursor.close()
    except mysql.connector.Error as e:
        # Log error but don't fail the request - recommendations are more important than storage
        print(f"Warning: Could not store recommendation (AUTO_INCREMENT may be maxed): {str(e)}")
        if conn:
            try:
                conn.rollback()
            except:
                pass
    except Exception as e:
        # Log error but don't fail the request
        print(f"Warning: Error storing recommendation: {str(e)}")
    finally:
        # Always close the connection
        if conn and conn.is_connected():
            conn.close()
    
    # Always return recommendations, even if storage failed
    response = {"recommendations": recommendations}
    if rec_id:
        response["rec_id"] = rec_id
    return jsonify(response)


@app.route("/evaluate", methods=["GET"])
def get_evaluation():
    """Calculate and return evaluation metrics for the recommendation system"""
    try:
        k_values = request.args.get('k', '3,5,10')
        k_values = [int(k.strip()) for k in k_values.split(',')]
        
        results = evaluate_recommendations(k_values=k_values)
        return jsonify(results)
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route("/evaluate/<item_type>", methods=["GET"])
def evaluate_item_type(item_type):
    """Get evaluation metrics for a specific item type (venues, themes, food)"""
    try:
        if item_type not in ['venues', 'themes', 'food']:
            return jsonify({"error": "Invalid item type. Must be 'venues', 'themes', or 'food'"}), 400
        
        k_values = request.args.get('k', '3,5,10')
        k_values = [int(k.strip()) for k in k_values.split(',')]
        
        results = evaluate_recommendations(k_values=k_values)
        
        if 'error' in results:
            return jsonify(results), 404
        
        if item_type not in results.get('metrics', {}):
            return jsonify({"error": f"No metrics available for {item_type}"}), 404
        
        return jsonify({
            item_type: results['metrics'][item_type],
            'overall': results.get('overall', {}),
            'timestamp': results.get('timestamp', '')
        })
    except Exception as e:
        return jsonify({"error": str(e)}), 500


if __name__ == "__main__":
    app.run(debug=True)
