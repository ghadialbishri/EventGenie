import mysql.connector
import pandas as pd
import json
import math
from datetime import datetime
from sklearn.neighbors import NearestNeighbors
from config import DB_CONFIG

"""Hybrid Recommender System
- Knowledge-based filtering → removes incompatible venues.
- Content-based scoring → food, themes, ratings.
- User-based CF → KNN finds similar users' preferences.
- Hybrid → additive combination of all scores.
- Stores recommendations in MySQL for future retrieval.
"""


def _to_float(value, default=0.0):
    """Safely convert DB values (which may be Decimal, str, None) to float."""
    if value is None:
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default

# Fetch venues
def fetch_venues():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        # Use JSON_EXTRACT to properly extract JSON fields
        # For event_type (array): extract as JSON array
        # For attrs (object): extract as JSON object
        cursor.execute(
            "SELECT venue_id, name, "
            "COALESCE(JSON_EXTRACT(event_type, '$'), JSON_ARRAY()) as event_type, "
            "capacity_min, capacity_max, gender_allowed, "
            "COALESCE(JSON_EXTRACT(attrs, '$'), JSON_OBJECT()) as attrs, "
            "avg_rating, rating_count FROM venues"
        )
        rows = cursor.fetchall()
        cursor.close()
        conn.close()
        return rows
    except mysql.connector.Error as e:
        print(f"Error fetching venues: {e}")
        return []


# Fetch feedback for Collaborative Filtering
def fetch_feedback():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        # Suppress pandas warning about mysql.connector (it works fine, just not "recommended")
        import warnings
        with warnings.catch_warnings():
            warnings.filterwarnings("ignore", category=UserWarning, module="pandas")
            df = pd.read_sql("SELECT user_id, item_id as venue_id, rating FROM feedback WHERE type = 'Venue' ", conn)
        conn.close()
        return df
    except (mysql.connector.Error, Exception) as e:
        print(f"Error fetching feedback: {e}")
        return pd.DataFrame()


# Fetch user preferences
def fetch_user_preferences(user_id):
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT preferred_styles, preferred_colors, indoor_outdoor, food FROM users WHERE user_id = %s", (user_id,))
        user = cursor.fetchone()
        cursor.close()
        conn.close()
        return user
    except mysql.connector.Error as e:
        print(f"Error fetching user preferences: {e}")
        return None

# Content + knowledge-based scoring
def content_score(venue, event_input, user_prefs=None):
    score = 0.0
    
    # Safely parse JSON fields - handle string, bytes, or already-parsed dict/list
    try:
        # Handle attrs
        attrs_raw = venue.get("attrs")
        if isinstance(attrs_raw, bytes):
            attrs = json.loads(attrs_raw.decode('utf-8')) if attrs_raw else {}
        elif isinstance(attrs_raw, str):
            attrs = json.loads(attrs_raw) if attrs_raw else {}
        else:
            attrs = attrs_raw if attrs_raw else {}
        
        # Handle event_type - decode bytes if needed
        event_type_raw = venue.get("event_type")
        if isinstance(event_type_raw, bytes):
            event_type_raw = event_type_raw.decode('utf-8')
        
        if isinstance(event_type_raw, str):
            event_types = json.loads(event_type_raw) if event_type_raw else []
        else:
            event_types = event_type_raw if event_type_raw else []
        
        # Ensure event_types is a list and all items are strings
        if not isinstance(event_types, list):
            event_types = []
        else:
            # Convert all items in event_types to strings (handle bytes, int, etc.)
            event_types = [str(item) if not isinstance(item, str) else item for item in event_types]
    except (json.JSONDecodeError, TypeError, UnicodeDecodeError):
        # If JSON parsing fails, return 0 score (incompatible venue)
        return 0

    # Hard constraints
    # event_types is a JSON array, check if event_type is in the array
    # Ensure event_input["event_type"] is also a string for comparison
    event_type_input = str(event_input.get("event_type", ""))
    if not event_types or event_type_input not in event_types:
        return 0
    if not (
        venue["capacity_min"] <= event_input["guest_count"] <= venue["capacity_max"]
    ):
        return 0
    if (
        venue["gender_allowed"] != "Both"
        and venue["gender_allowed"] != event_input["gender"]
    ):
        return 0

    # Content-based scoring
    if "food_pref" in event_input and event_input["food_pref"] in attrs.get("food", []):
        score += 1.0

    # User preferences scoring
    if user_prefs:
        if user_prefs.get("food") and user_prefs["food"] in attrs.get("food", []):
            score += 0.5
        if user_prefs.get("indoor_outdoor") and user_prefs["indoor_outdoor"] in attrs.get("indoor_outdoor", []):
            score += 0.5
        # Add more preferences if available

    # Add avg_rating boost
    avg_rating = _to_float(venue.get("avg_rating"))
    rating_count = _to_float(venue.get("rating_count"))
    if rating_count > 0:
        score += avg_rating / 5.0  # normalize 0..1

    return min(score, 1.0)


# User-based CF using KNN
def user_based_cf(event_input, venues, k=3):
    df_feedback = fetch_feedback()
    if df_feedback.empty:
        return {}

    pivot = df_feedback.pivot_table(
        index="user_id", columns="venue_id", values="rating", aggfunc="mean"
    ).fillna(0.0)
    pivot = pivot.map(_to_float)
    n_users = pivot.shape[0]
    if n_users < 2:
        return {}

    # Adjust neighbor count to available users (exclude self)
    k_adjusted = min(max(k, 1), n_users - 1)
    if k_adjusted <= 0:
        return {}

    knn = NearestNeighbors(metric="cosine", algorithm="brute")
    knn.fit(pivot)
    try:
        user_vector = pivot.loc[event_input["user_id"]].values.reshape(1, -1)
        n_neighbors = min(k_adjusted + 1, n_users)
        _, indices = knn.kneighbors(user_vector, n_neighbors=n_neighbors)
        similar_users = pivot.index[indices.flatten()[1:]]
    except KeyError:
        similar_users = []
    except ValueError:
        return {}

    scores = {}
    for v in venues:
        venue_id = v["venue_id"]
        ratings = df_feedback[
            (df_feedback["user_id"].isin(similar_users))
            & (df_feedback["venue_id"] == venue_id)
        ]["rating"]
        if not ratings.empty:
            scores[venue_id] = float(ratings.mean())
    return scores


def _compute_popularity_scores(venues):
    """Derive popularity prior from Google rating aggregates."""
    counts = [
        _to_float(v.get("rating_count"))
        for v in venues
        if _to_float(v.get("rating_count")) > 0
    ]
    max_count = max(counts) if counts else 1

    popularity = {}
    for v in venues:
        venue_id = v["venue_id"]
        avg_rating = _to_float(v.get("avg_rating"))
        rating_count = _to_float(v.get("rating_count"))
        rating_component = max(min(avg_rating, 5), 0) / 5
        count_component = math.log1p(max(rating_count, 0)) / math.log1p(max_count)
        popularity[venue_id] = round(rating_component * count_component, 4)
    return popularity


# Hybrid recommendation
def recommend_venues(event_input, top_k=5):
    venues = fetch_venues()
    scored = []

    user_cf_scores = user_based_cf(event_input, venues)
    popularity_scores = _compute_popularity_scores(venues)

    # Fetch user preferences
    user_prefs = fetch_user_preferences(event_input["user_id"])

    weights = {
        "content": 0.7,
        "cf": 0.1 if user_cf_scores else 0.0,
        "popularity": 0.2,
    }
    weight_sum = sum(weights.values()) or 1.0

    for v in venues:
        cs = _to_float(content_score(v, event_input, user_prefs))
        uid = v["venue_id"]
        # Normalize CF score from 0-5 rating range to 0-1
        cf_score_raw = _to_float(user_cf_scores.get(uid))
        cf_score = cf_score_raw / 5.0 if cf_score_raw > 0 else 0.0
        pop_score = _to_float(popularity_scores.get(uid))
        total_score = (
            weights["content"] * cs
            + weights["cf"] * cf_score
            + weights["popularity"] * pop_score
        ) / weight_sum
        # Ensure score is normalized to 0-1
        total_score = min(max(total_score, 0.0), 1.0)
        if total_score > 0:
            scored.append(
                {
                    "venue_id": uid,
                    "name": v["name"],
                    "score": round(total_score, 2),
                    "components": {
                        "content": round(cs, 2),
                        "cf": round(cf_score, 2),
                        "popularity": round(pop_score, 2),
                    },
                }
            )

    scored_sorted = sorted(scored, key=lambda x: x["score"], reverse=True)
    return scored_sorted[:top_k]


# Recommend themes based on event type, user preferences, and theme attributes
def recommend_themes(event_input, top_k=3):
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # Fetch theme popularity (usage count from events)
        cursor.execute("""
            SELECT theme_id, COUNT(*) as usage_count
            FROM events
            WHERE theme_id IS NOT NULL
            GROUP BY theme_id
        """)
        theme_popularity = {row['theme_id']: row['usage_count'] for row in cursor.fetchall()}
        max_popularity = max(theme_popularity.values()) if theme_popularity else 1
        
        # Fetch all theme details including event_type, category, cultural_fit, tags, season, color_palette
        cursor.execute("""
            SELECT id as theme_id, name, category, cultural_fit, tags, season, color_palette, image_url,
            COALESCE(JSON_EXTRACT(event_type, '$'), JSON_ARRAY()) as event_type
            FROM event_themes
        """)
        themes = cursor.fetchall()
        cursor.close()
        conn.close()

        # Fetch user preferences for scoring
        user_prefs = fetch_user_preferences(event_input["user_id"])
        
        # Get event date if available for season matching
        event_date = event_input.get("event_date")
        event_season = None
        if event_date:
            try:
                date_obj = datetime.strptime(event_date, "%Y-%m-%d")
                month = date_obj.month
                # Determine season (Northern Hemisphere)
                if month in [12, 1, 2]:
                    event_season = "Winter"
                elif month in [3, 4, 5]:
                    event_season = "Spring"
                elif month in [6, 7, 8]:
                    event_season = "Summer"
                else:
                    event_season = "Fall"
            except (ValueError, TypeError):
                pass

        scored = []
        for t in themes:
            score = 0.0
            base_score = 0.0
            
            # Parse JSON fields - handle bytes, string, or already-parsed
            try:
                event_type_raw = t.get("event_type")
                if isinstance(event_type_raw, bytes):
                    event_type_raw = event_type_raw.decode('utf-8')
                
                if isinstance(event_type_raw, str):
                    theme_event_types = json.loads(event_type_raw) if event_type_raw else []
                else:
                    theme_event_types = event_type_raw if event_type_raw else []
                
                # Ensure theme_event_types is a list and all items are strings
                if not isinstance(theme_event_types, list):
                    theme_event_types = []
                else:
                    theme_event_types = [str(item) if not isinstance(item, str) else item for item in theme_event_types]
                
                # Parse tags if it's a string or bytes
                theme_tags = []
                tags_raw = t.get("tags")
                if tags_raw:
                    if isinstance(tags_raw, bytes):
                        tags_raw = tags_raw.decode('utf-8')
                    if isinstance(tags_raw, str):
                        theme_tags = [tag.strip().lower() for tag in tags_raw.split(",") if tag.strip()]
                    else:
                        theme_tags = [str(tag).lower() for tag in tags_raw] if tags_raw else []
            except (json.JSONDecodeError, TypeError, UnicodeDecodeError):
                theme_event_types = []
                theme_tags = []

            # 1. Event type matching (most important - hard constraint)
            event_type_input = str(event_input.get("event_type", ""))
            if not theme_event_types or event_type_input not in theme_event_types:
                # Skip themes that don't match event type
                continue
            
            # Base score for event type match (increased weight)
            base_score += 0.6
            
            # 2. Cultural fit scoring (important for ranking)
            cultural_fit = t.get("cultural_fit", "")
            cultural_fit_scores = {
                "Very High": 0.25,
                "High": 0.15,
                "Medium": 0.08,
                "Low": 0.03
            }
            base_score += cultural_fit_scores.get(cultural_fit, 0.0)
            
            # 3. User preferred styles matching (enhanced)
            style_match_score = 0.0
            if user_prefs and user_prefs.get("preferred_styles"):
                user_styles = user_prefs["preferred_styles"]
                if isinstance(user_styles, str):
                    user_styles = [s.strip().lower() for s in user_styles.split(",") if s.strip()]
                else:
                    user_styles = [str(s).lower() for s in ([user_styles] if user_styles else [])]
                
                theme_name_lower = t["name"].lower()
                # Check if theme name matches any preferred style (exact or partial)
                for style in user_styles:
                    if style in theme_name_lower or theme_name_lower in style:
                        style_match_score += 0.2  # Strong match
                        break
                    # Check for word-level matches
                    elif any(style in word or word in style for word in theme_name_lower.split()):
                        style_match_score += 0.15
                        break
                
                # Check if tags match preferred styles
                for tag in theme_tags:
                    for style in user_styles:
                        if style in tag or tag in style:
                            style_match_score += 0.1
                            break
                    if style_match_score > 0:
                        break
            
            score += style_match_score
            
            # 4. User preferred colors matching (enhanced)
            color_match_score = 0.0
            if user_prefs and user_prefs.get("preferred_colors") and t.get("color_palette"):
                user_colors = user_prefs["preferred_colors"]
                if isinstance(user_colors, str):
                    user_colors = [c.strip().lower() for c in user_colors.split(",") if c.strip()]
                else:
                    user_colors = [str(user_colors).lower()] if user_colors else []
                
                theme_color_palette = str(t["color_palette"]).lower()
                
                # Check for color matches
                for user_color in user_colors:
                    # Direct color name matching
                    if user_color in theme_color_palette:
                        color_match_score += 0.15
                        break
                    # Check for color variations
                    elif any(user_color in word or word in user_color 
                            for word in theme_color_palette.split()):
                        color_match_score += 0.1
                        break
            
            score += color_match_score
            
            # 5. Season matching (enhanced)
            if event_season and t.get("season"):
                theme_seasons = [s.strip().lower() for s in str(t["season"]).split(",") if s.strip()]
                if event_season.lower() in theme_seasons:
                    score += 0.12  # Strong season match
                elif "all" in theme_seasons or "any" in theme_seasons:
                    score += 0.06  # Partial match for "all season" themes
            
            # 6. Category matching (enhanced)
            category = t.get("category", "")
            category_bonus = {
                "Cultural": 0.12,  # Higher for cultural themes
                "Nature": 0.08,
                "Elegant": 0.06,
                "Modern": 0.05,
                "Vintage": 0.05
            }
            score += category_bonus.get(category, 0.0)
            
            # 7. Popularity boost (normalized)
            theme_id = t["theme_id"]
            popularity_count = theme_popularity.get(theme_id, 0)
            popularity_score = (popularity_count / max_popularity) * 0.1 if max_popularity > 0 else 0.0
            score += popularity_score
            
            # Combine base score with feature scores
            total_score = base_score + score
            
            # Apply minimum threshold - only recommend themes with score > 0.3
            if total_score < 0.3:
                continue
            
            # Normalize theme score to 0-1 range
            # Max possible: 0.6 (base) + 0.25 (cultural) + 0.2 (style) + 0.15 (color) + 0.12 (season) + 0.12 (category) + 0.1 (popularity) = ~1.54
            max_possible_score = 1.6
            normalized_score = min(total_score / max_possible_score, 1.0)
            
            scored.append({
                "theme_id": theme_id,
                "name": t["name"],
                "score": round(normalized_score, 4),  # More precision for better ranking
                "image_url": t.get("image_url")
            })

        # Sort by score (descending) and return top_k
        scored_sorted = sorted(scored, key=lambda x: x["score"], reverse=True)
        
        # Return top_k, but ensure we have at least some results
        if len(scored_sorted) == 0:
            # Fallback: return themes that match event type even with low scores
            event_type_input = str(event_input.get("event_type", ""))
            for t in themes:
                try:
                    event_type_raw = t.get("event_type")
                    if isinstance(event_type_raw, bytes):
                        event_type_raw = event_type_raw.decode('utf-8')
                    
                    if isinstance(event_type_raw, str):
                        theme_event_types = json.loads(event_type_raw) if event_type_raw else []
                    else:
                        theme_event_types = event_type_raw if event_type_raw else []
                    
                    # Ensure theme_event_types is a list and all items are strings
                    if not isinstance(theme_event_types, list):
                        theme_event_types = []
                    else:
                        theme_event_types = [str(item) if not isinstance(item, str) else item for item in theme_event_types]
                    
                    if theme_event_types and event_type_input in theme_event_types:
                        scored_sorted.append({
                            "theme_id": t["theme_id"],
                            "name": t["name"],
                            "score": 0.3,  # Minimum score
                            "image_url": t.get("image_url")
                        })
                        if len(scored_sorted) >= top_k:
                            break
                except (KeyError, TypeError, json.JSONDecodeError, UnicodeDecodeError):
                    continue
        
        return scored_sorted[:top_k]
        
    except mysql.connector.Error as e:
        print(f"Error fetching themes: {e}")
        return []


# Recommend food based on cafe/restaurant venues
def recommend_food(event_input, top_k=3):
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # Fetch venues that are cafes/restaurants with JSON_EXTRACT for attrs
        cursor.execute("""
            SELECT COALESCE(JSON_EXTRACT(attrs, '$'), JSON_OBJECT()) as attrs, venue_category 
            FROM venues 
            WHERE venue_category LIKE '%Restaurant%' OR venue_category LIKE '%Cafe%'
        """)
        restaurant_venues = cursor.fetchall()
        cursor.close()
        conn.close()

        # Extract all unique food types from restaurant/cafe venues
        all_food_types = set()
        for venue in restaurant_venues:
            try:
                attrs_raw = venue.get("attrs")
                if isinstance(attrs_raw, bytes):
                    attrs = json.loads(attrs_raw.decode('utf-8')) if attrs_raw else {}
                elif isinstance(attrs_raw, str):
                    attrs = json.loads(attrs_raw) if attrs_raw else {}
                else:
                    attrs = attrs_raw if attrs_raw else {}
                
                food_options = attrs.get("food", [])
                if isinstance(food_options, list):
                    for food in food_options:
                        # Filter out non-food options like "Exclusive Catering Only", "In-House Beverage Service"
                        if food and food not in ["Exclusive Catering Only", "In-House Beverage Service", "In-house Catering or Restaurants/Cafes"]:
                            all_food_types.add(food)
            except (json.JSONDecodeError, TypeError, UnicodeDecodeError):
                continue

        # If no restaurant venues found, fallback to common types
        if not all_food_types:
            all_food_types = {"Arabic", "Middle Eastern", "Italian", "Seafood", "Fusion", "Japanese", "Mexican", "French", "American", "Continental", "Saudi", "Brazilian", "Indian", "Spanish", "Peruvian", "Chinese", "Turkish"}

        # Score food types based on user preferences and event input
        user_prefs = fetch_user_preferences(event_input["user_id"])
        scored = []
        
        for food_type in sorted(all_food_types):
            score = 0.0
            
            # Match user preferences
            if user_prefs and user_prefs.get("food") == food_type:
                score += 0.2  # Higher weight for user preference
            
            # Match event input food preference
            if "food_pref" in event_input and event_input["food_pref"] == food_type:
                score += 0.15
            
            # If user preference is similar (e.g., "Arabic" matches "Middle Eastern")
            if user_prefs and user_prefs.get("food"):
                user_food = user_prefs["food"].lower()
                if food_type.lower() in user_food or user_food in food_type.lower():
                    score += 0.05
            
            # Normalize food score to 0-1 range (max possible is 0.4: 0.2 + 0.15 + 0.05)
            max_possible_score = 0.4
            normalized_score = min(score / max_possible_score, 1.0) if max_possible_score > 0 else 0.0
            
            scored.append({"food": food_type, "score": round(normalized_score, 2)})

        # Sort by score and return top_k
        scored_sorted = sorted(scored, key=lambda x: x["score"], reverse=True)
        
        # Return top_k items (may be fewer if not enough food types available)
        return scored_sorted[:top_k]
        
    except mysql.connector.Error as e:
        print(f"Error fetching food recommendations: {e}")
        # Fallback to basic food types
        food_types = ["Arabic", "Middle Eastern", "Italian", "Seafood", "Fusion", "Japanese", "Mexican", "French", "American"]
        user_prefs = fetch_user_preferences(event_input["user_id"])
        scored = []
        for f in food_types:
            score = 0.0 
            if user_prefs and user_prefs.get("food") == f:
                score += 0.1 # Higher weight for user preference
            
            # Normalize food score to 0-1 range (max possible is 0.1 in fallback)
            max_possible_score = 0.1
            normalized_score = min(score / max_possible_score, 1.0) if max_possible_score > 0 else 0.0
            
            scored.append({"food": f, "score": round(normalized_score, 2)})
        scored_sorted = sorted(scored, key=lambda x: x["score"], reverse=True)
        return scored_sorted[:top_k]
