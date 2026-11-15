import mysql.connector
import json
import numpy as np
from collections import defaultdict
from datetime import datetime, timedelta
from config import DB_CONFIG

"""Evaluation Metrics for Recommendation System

Primary Metrics (used in accuracy reports):
- Mean Average Precision (MAP): Average precision across all users/events, considering ranking order
- NDCG@K: Normalized Discounted Cumulative Gain - measures ranking quality with position discounting

Secondary Metrics (calculated but not displayed in reports):
- Precision@K: Fraction of recommended items that are relevant
- Recall@K: Fraction of relevant items that are recommended
- Hit Rate: Percentage of users who received at least one relevant recommendation
- Diversity: Intra-list diversity of recommendations
- Coverage: Percentage of items that can be recommended
- Popularity Bias: Measures if recommendations favor popular items
"""


def fetch_recommendations_with_events():
    """Fetch recommendations and corresponding events (actual selections)"""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # Fetch recommendations
        cursor.execute("""
            SELECT rec_id, user_id, input_json, items_json, created_at
            FROM recommendations
            WHERE status = 'active'
            ORDER BY created_at DESC
        """)
        recommendations = cursor.fetchall()
        
        # Fetch events with selections (venues, themes, food)
        cursor.execute("""
            SELECT event_id, user_id, event_type, chosen_venue_id, theme_id, food_pref, created_at
            FROM events
            WHERE chosen_venue_id IS NOT NULL
            ORDER BY created_at DESC
        """)
        events = cursor.fetchall()
        
        cursor.close()
        conn.close()
        
        return recommendations, events
    except mysql.connector.Error as e:
        print(f"Error fetching recommendations/events: {e}")
        return [], []


def match_recommendations_to_events(recommendations, events):
    """Match recommendations to events based on user_id and time proximity"""
    matched_pairs = []
    
    # Group events by user_id
    events_by_user = defaultdict(list)
    for event in events:
        events_by_user[event['user_id']].append(event)
    
    for rec in recommendations:
        try:
            input_data = json.loads(rec['input_json']) if isinstance(rec['input_json'], str) else rec['input_json']
            items_data = json.loads(rec['items_json']) if isinstance(rec['items_json'], str) else rec['items_json']
            user_id = rec['user_id']
            
            # Find matching event within 24 hours of recommendation
            rec_time = rec['created_at']
            if isinstance(rec_time, str):
                rec_time = datetime.strptime(rec_time, '%Y-%m-%d %H:%M:%S')
            
            matching_events = []
            for event in events_by_user.get(user_id, []):
                event_time = event['created_at']
                if isinstance(event_time, str):
                    event_time = datetime.strptime(event_time, '%Y-%m-%d %H:%M:%S')
                
                # Check if event was created within 24 hours of recommendation
                time_diff = abs((rec_time - event_time).total_seconds())
                if time_diff <= 86400:  # 24 hours in seconds
                    matching_events.append(event)
            
            if matching_events:
                # Use the closest event in time
                closest_event = min(
                    matching_events,
                    key=lambda e: abs((rec_time - (e['created_at'] if isinstance(e['created_at'], datetime) else datetime.strptime(e['created_at'], '%Y-%m-%d %H:%M:%S'))).total_seconds())
                )
                matched_pairs.append({
                    'recommendation': rec,
                    'event': closest_event,
                    'input_data': input_data,
                    'items_data': items_data
                })
        except (json.JSONDecodeError, KeyError, ValueError) as e:
            print(f"Error processing recommendation {rec.get('rec_id', 'unknown')}: {e}")
            continue
    
    return matched_pairs


def extract_recommended_items(items_data):
    """Extract recommended venue IDs, theme IDs, and food types from recommendation
    Returns lists (preserving order) for proper top-K evaluation"""
    venues = [item.get('venue_id') for item in items_data.get('venues', []) if item.get('venue_id')]
    themes = [item.get('theme_id') for item in items_data.get('themes', []) if item.get('theme_id')]
    food = [item.get('food') for item in items_data.get('food', []) if item.get('food')]
    return venues, themes, food


def extract_relevant_items(event):
    """Extract relevant (selected) venue ID, theme ID, and food from event"""
    venues = {event['chosen_venue_id']} if event.get('chosen_venue_id') else set()
    themes = {event['theme_id']} if event.get('theme_id') else set()
    food = {event['food_pref']} if event.get('food_pref') else set()
    return venues, themes, food


def precision_at_k(recommended, relevant, k):
    """
    Calculate Precision@K (secondary metric - not displayed in reports)
    
    Args:
        recommended: list of recommended items (ordered by score)
        relevant: set of relevant items
        k: number of top items to consider
    
    Returns:
        Precision@K value (0.0 to 1.0)
    """
    if k == 0 or len(recommended) == 0:
        return 0.0
    
    # Ensure recommended is a list and take top k
    recommended_list = list(recommended)[:k] if isinstance(recommended, (list, tuple)) else list(recommended)[:k]
    relevant_set = set(relevant) if not isinstance(relevant, set) else relevant
    
    if len(recommended_list) == 0:
        return 0.0
    
    relevant_count = sum(1 for item in recommended_list if item in relevant_set)
    return relevant_count / len(recommended_list)


def recall_at_k(recommended, relevant, k):
    """
    Calculate Recall@K (secondary metric - not displayed in reports)
    
    Args:
        recommended: list of recommended items (ordered by score)
        relevant: set of relevant items
        k: number of top items to consider
    
    Returns:
        Recall@K value (0.0 to 1.0)
    """
    relevant_set = set(relevant) if not isinstance(relevant, set) else relevant
    
    if len(relevant_set) == 0:
        return 1.0 if len(recommended) == 0 else 0.0
    
    # Ensure recommended is a list and take top k
    recommended_list = list(recommended)[:k] if isinstance(recommended, (list, tuple)) else list(recommended)[:k]
    relevant_count = sum(1 for item in recommended_list if item in relevant_set)
    return relevant_count / len(relevant_set)


def average_precision(recommended, relevant):
    """
    Calculate Average Precision (AP) - used for MAP calculation (primary metric)
    
    Args:
        recommended: list of recommended items (ordered by score)
        relevant: set of relevant items
    
    Returns:
        Average Precision value (0.0 to 1.0)
    """
    relevant_set = set(relevant) if not isinstance(relevant, set) else relevant
    
    if len(relevant_set) == 0:
        return 0.0
    
    # Ensure recommended is a list
    recommended_list = list(recommended) if isinstance(recommended, (list, tuple)) else list(recommended)
    relevant_indices = [i + 1 for i, item in enumerate(recommended_list) if item in relevant_set]
    
    if len(relevant_indices) == 0:
        return 0.0
    
    precisions = [len([idx for idx in relevant_indices if idx <= pos]) / pos 
                  for pos in relevant_indices]
    return sum(precisions) / len(relevant_set) if len(relevant_set) > 0 else 0.0


def dcg_at_k(scores, k):
    """Calculate Discounted Cumulative Gain at K"""
    scores = scores[:k]
    return sum(score / np.log2(i + 2) for i, score in enumerate(scores))


def ndcg_at_k(recommended, relevant, k):
    """
    Calculate Normalized Discounted Cumulative Gain@K (primary metric - used in accuracy reports)
    
    Measures ranking quality with position discounting - gives more weight to relevant items
    appearing earlier in the recommendation list.
    
    Args:
        recommended: list of recommended items (ordered by score)
        relevant: set of relevant items
        k: number of top items to consider
    
    Returns:
        NDCG@K value (0.0 to 1.0), where 1.0 means perfect ranking
    """
    relevant_set = set(relevant) if not isinstance(relevant, set) else relevant
    
    if len(relevant_set) == 0:
        return 0.0
    
    # Ensure recommended is a list and take top k
    recommended_list = list(recommended)[:k] if isinstance(recommended, (list, tuple)) else list(recommended)[:k]
    relevance_scores = [1.0 if item in relevant_set else 0.0 for item in recommended_list]
    
    # Calculate DCG
    dcg = dcg_at_k(relevance_scores, k)
    
    # Calculate ideal DCG (all relevant items first)
    ideal_relevance = [1.0] * min(len(relevant_set), k) + [0.0] * max(0, k - len(relevant_set))
    ideal_dcg = dcg_at_k(ideal_relevance, k)
    
    return dcg / ideal_dcg if ideal_dcg > 0 else 0.0


def calculate_diversity(recommendations_list):
    """
    Calculate intra-list diversity using Jaccard distance (secondary metric)
    
    Measures how different items are within recommendation lists.
    Higher values indicate more diverse recommendations.
    """
    if len(recommendations_list) <= 1:
        return 0.0
    
    total_distance = 0.0
    count = 0
    
    for i in range(len(recommendations_list)):
        for j in range(i + 1, len(recommendations_list)):
            set1 = set(recommendations_list[i]) if not isinstance(recommendations_list[i], set) else recommendations_list[i]
            set2 = set(recommendations_list[j]) if not isinstance(recommendations_list[j], set) else recommendations_list[j]
            
            # Jaccard distance: 1 - Jaccard similarity
            intersection = len(set1 & set2)
            union = len(set1 | set2)
            distance = 1.0 - (intersection / union) if union > 0 else 0.0
            
            total_distance += distance
            count += 1
    
    return total_distance / count if count > 0 else 0.0


def calculate_coverage(all_recommendations, all_items):
    """
    Calculate coverage: percentage of items that were recommended (secondary metric)
    
    Measures how many different items from the catalog are being recommended.
    Higher values indicate broader item coverage.
    """
    recommended_items = set()
    for rec_items in all_recommendations:
        recommended_items.update(rec_items)
    
    if len(all_items) == 0:
        return 0.0
    
    return len(recommended_items) / len(all_items) * 100.0


def calculate_popularity_bias(recommendations_list, item_popularity):
    """
    Calculate popularity bias: correlation between recommendation frequency and popularity (secondary metric)
    
    Returns correlation coefficient (-1.0 to 1.0):
    - Positive: System favors popular items
    - Negative: System favors less popular items
    - Near 0: Balanced recommendations
    """
    if len(recommendations_list) == 0:
        return 0.0
    
    # Count how often each item is recommended
    rec_frequency = defaultdict(int)
    for rec_items in recommendations_list:
        for item in rec_items:
            rec_frequency[item] += 1
    
    # Calculate correlation between recommendation frequency and popularity
    items = list(set(item for rec_items in recommendations_list for item in rec_items))
    if len(items) == 0:
        return 0.0
    
    frequencies = [rec_frequency[item] for item in items]
    popularities = [item_popularity.get(item, 0) for item in items]
    
    if len(frequencies) == 0 or len(frequencies) < 2:
        return 0.0
    
    # Convert to numpy arrays
    try:
        freq_array = np.array(frequencies, dtype=float)
        pop_array = np.array(popularities, dtype=float)
    except (TypeError, ValueError):
        return 0.0
    
    # Check if we have variation in popularities
    try:
        pop_std = float(np.std(pop_array))
        if pop_std == 0 or (isinstance(pop_std, float) and (pop_std != pop_std)):  # Check for NaN
            return 0.0
    except (TypeError, ValueError):
        return 0.0
    
    try:
        correlation_matrix = np.corrcoef(freq_array, pop_array)
        correlation = float(correlation_matrix[0, 1]) if correlation_matrix.size > 1 else 0.0
        return correlation if (correlation == correlation) else 0.0  # Check for NaN
    except (ValueError, RuntimeWarning, TypeError, IndexError):
        return 0.0


def fetch_item_popularity():
    """Fetch popularity metrics for items (venues, themes, food)"""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        # Venue popularity (from avg_rating and rating_count)
        cursor.execute("""
            SELECT venue_id, (avg_rating * rating_count) as popularity_score
            FROM venues
            WHERE rating_count > 0
        """)
        venue_popularity = {row['venue_id']: row['popularity_score'] for row in cursor.fetchall()}
        
        # Theme popularity (from events count)
        cursor.execute("""
            SELECT theme_id, COUNT(*) as popularity_score
            FROM events
            WHERE theme_id IS NOT NULL
            GROUP BY theme_id
        """)
        theme_popularity = {row['theme_id']: row['popularity_score'] for row in cursor.fetchall()}
        
        # Food popularity (from events count)
        cursor.execute("""
            SELECT food_pref, COUNT(*) as popularity_score
            FROM events
            WHERE food_pref IS NOT NULL
            GROUP BY food_pref
        """)
        food_popularity = {row['food_pref']: row['popularity_score'] for row in cursor.fetchall()}
        
        cursor.close()
        conn.close()
        
        return venue_popularity, theme_popularity, food_popularity
    except mysql.connector.Error as e:
        print(f"Error fetching item popularity: {e}")
        return {}, {}, {}


def evaluate_recommendations(k_values=[3, 5, 10]):
    """
    Calculate evaluation metrics for the recommendation system.
    
    Primary focus: MAP and NDCG@K (used in accuracy reports)
    Also calculates: Precision@K, Recall@K, Hit Rate, Diversity, Coverage, Popularity Bias
    
    Args:
        k_values: List of K values for evaluation (default: [3, 5, 10])
    
    Returns:
        Dictionary containing:
        - metrics: Dict with 'venues', 'themes', 'food' metrics
        - overall: Overall statistics (total recommendations, events, matched pairs, match rate)
        - timestamp: ISO timestamp of evaluation
        - error: Error message if no matched pairs found
    """
    recommendations, events = fetch_recommendations_with_events()
    matched_pairs = match_recommendations_to_events(recommendations, events)
    
    if len(matched_pairs) == 0:
        return {
            'error': 'No matched recommendation-event pairs found',
            'total_recommendations': len(recommendations),
            'total_events': len(events),
            'matched_pairs': 0
        }
    
    # Initialize metrics
    metrics = {
        'venues': {},
        'themes': {},
        'food': {}
    }
    
    # Fetch all items for coverage calculation
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        cursor.execute("SELECT venue_id FROM venues")
        all_venue_ids = {row['venue_id'] for row in cursor.fetchall()}
        
        cursor.execute("SELECT id as theme_id FROM event_themes")
        all_theme_ids = {row['theme_id'] for row in cursor.fetchall()}
        
        cursor.execute("SELECT DISTINCT food_pref FROM events WHERE food_pref IS NOT NULL")
        all_food_types = {row['food_pref'] for row in cursor.fetchall()}
        
        cursor.close()
        conn.close()
    except mysql.connector.Error as e:
        print(f"Error fetching all items for coverage: {e}")
        all_venue_ids = set()
        all_theme_ids = set()
        all_food_types = set()
    
    # Fetch popularity data
    venue_pop, theme_pop, food_pop = fetch_item_popularity()
    
    # Process each recommendation type
    for item_type in ['venues', 'themes', 'food']:
        type_metrics = {}
        
        # Calculate Precision@K, Recall@K, MAP, NDCG@K for each K
        precisions = {k: [] for k in k_values}
        recalls = {k: [] for k in k_values}
        aps = []
        ndcgs = {k: [] for k in k_values}
        
        recommended_lists = []
        all_recommended_items = []
        popularity_freq = {}
        
        for pair in matched_pairs:
            rec_venues, rec_themes, rec_food = extract_recommended_items(pair['items_data'])
            rel_venues, rel_themes, rel_food = extract_relevant_items(pair['event'])
            
            if item_type == 'venues':
                recommended = rec_venues
                relevant = set(rel_venues)  # Convert to set for membership testing
                all_items = all_venue_ids
                popularity = venue_pop
            elif item_type == 'themes':
                recommended = rec_themes
                relevant = set(rel_themes)  # Convert to set for membership testing
                all_items = all_theme_ids
                popularity = theme_pop
            else:  # food
                recommended = rec_food
                relevant = set(rel_food)  # Convert to set for membership testing
                all_items = all_food_types
                popularity = food_pop
            
            if len(recommended) == 0:
                continue
            
            # Calculate metrics for each K
            for k in k_values:
                precisions[k].append(precision_at_k(recommended, relevant, k))
                recalls[k].append(recall_at_k(recommended, relevant, k))
                ndcgs[k].append(ndcg_at_k(recommended, relevant, k))
            
            # Calculate MAP
            aps.append(average_precision(recommended, relevant))
            
            # Collect for diversity and coverage
            recommended_lists.append(set(recommended))  # Convert to set for diversity calculation
            all_recommended_items.extend(recommended)
            
            # Track popularity
            for item in recommended:
                popularity_freq[item] = popularity_freq.get(item, 0) + popularity.get(item, 0)
        
        # Aggregate metrics
        # Primary metrics (used in accuracy reports)
        for k in k_values:
            type_metrics[f'ndcg@{k}'] = np.mean(ndcgs[k]) if ndcgs[k] else 0.0
        
        type_metrics['map'] = np.mean(aps) if aps else 0.0
        
        # Secondary metrics (calculated but not displayed in reports)
        for k in k_values:
            type_metrics[f'precision@{k}'] = np.mean(precisions[k]) if precisions[k] else 0.0
            type_metrics[f'recall@{k}'] = np.mean(recalls[k]) if recalls[k] else 0.0
        
        type_metrics['hit_rate'] = sum(1 for ap in aps if ap > 0) / len(aps) * 100.0 if aps else 0.0
        type_metrics['diversity'] = calculate_diversity(recommended_lists) if recommended_lists else 0.0
        type_metrics['coverage'] = calculate_coverage(recommended_lists, all_items) if all_items else 0.0
        type_metrics['popularity_bias'] = calculate_popularity_bias(recommended_lists, popularity) if recommended_lists else 0.0
        
        metrics[item_type] = type_metrics
    
    # Overall metrics
    overall_metrics = {
        'total_recommendations': len(recommendations),
        'total_events': len(events),
        'matched_pairs': len(matched_pairs),
        'match_rate': len(matched_pairs) / len(recommendations) * 100.0 if recommendations else 0.0
    }
    
    return {
        'metrics': metrics,
        'overall': overall_metrics,
        'timestamp': datetime.now().isoformat()
    }

