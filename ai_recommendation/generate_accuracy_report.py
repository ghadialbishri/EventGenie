"""
Generate Accuracy Report for Recommendation System
Calculates and displays comprehensive evaluation metrics
"""

import json
from datetime import datetime
from evaluation import evaluate_recommendations


def format_percentage(value):
    """Format value as percentage"""
    return f"{value * 100:.2f}%"


def format_decimal(value, decimals=4):
    """Format value as decimal"""
    return f"{value:.{decimals}f}"


def generate_accuracy_report(k_values=[3, 5, 10], output_file=None):
    """
    Generate comprehensive accuracy report
    
    Args:
        k_values: List of K values for evaluation (default: [3, 5, 10])
        output_file: Optional file path to save report as JSON
    """
    print("=" * 80)
    print("RECOMMENDATION SYSTEM ACCURACY REPORT")
    print("=" * 80)
    print(f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"K Values: {', '.join(map(str, k_values))}")
    print("=" * 80)
    
    # Run evaluation
    print("\nEvaluating recommendation system...")
    results = evaluate_recommendations(k_values=k_values)
    
    if 'error' in results:
        print(f"\nERROR: {results['error']}")
        print(f"Total Recommendations: {results.get('total_recommendations', 0)}")
        print(f"Total Events: {results.get('total_events', 0)}")
        print(f"Matched Pairs: {results.get('matched_pairs', 0)}")
        print("\nWARNING: Not enough data to generate accuracy report.")
        print("   Need matched recommendation-event pairs for evaluation.")
        return results
    
    # Overall Statistics
    print("\n" + "=" * 80)
    print("OVERALL STATISTICS")
    print("=" * 80)
    overall = results.get('overall', {})
    print(f"Total Recommendations: {overall.get('total_recommendations', 0)}")
    print(f"Total Events: {overall.get('total_events', 0)}")
    print(f"Matched Pairs: {overall.get('matched_pairs', 0)}")
    print(f"Match Rate: {format_percentage(overall.get('match_rate', 0) / 100)}")
    
    metrics = results.get('metrics', {})
    
    # Venue Metrics
    print("\n" + "=" * 80)
    print("VENUE RECOMMENDATION ACCURACY")
    print("=" * 80)
    venue_metrics = metrics.get('venues', {})
    if venue_metrics:
        print("\nNDCG@K (Normalized Discounted Cumulative Gain):")
        for k in k_values:
            ndcg = venue_metrics.get(f'ndcg@{k}', 0)
            print(f"   NDCG@{k}: {format_decimal(ndcg)}")
        
        print(f"\nMean Average Precision (MAP): {format_decimal(venue_metrics.get('map', 0))}")
    else:
        print("WARNING: No venue metrics available")
    
    # Theme Metrics
    print("\n" + "=" * 80)
    print("THEME RECOMMENDATION ACCURACY")
    print("=" * 80)
    theme_metrics = metrics.get('themes', {})
    if theme_metrics:
        print("\nNDCG@K (Normalized Discounted Cumulative Gain):")
        for k in k_values:
            ndcg = theme_metrics.get(f'ndcg@{k}', 0)
            print(f"   NDCG@{k}: {format_decimal(ndcg)}")
        
        print(f"\nMean Average Precision (MAP): {format_decimal(theme_metrics.get('map', 0))}")
    else:
        print("WARNING: No theme metrics available")
    
    # Food Metrics
    print("\n" + "=" * 80)
    print("FOOD RECOMMENDATION ACCURACY")
    print("=" * 80)
    food_metrics = metrics.get('food', {})
    if food_metrics:
        print("\nNDCG@K (Normalized Discounted Cumulative Gain):")
        for k in k_values:
            ndcg = food_metrics.get(f'ndcg@{k}', 0)
            print(f"   NDCG@{k}: {format_decimal(ndcg)}")
        
        print(f"\nMean Average Precision (MAP): {format_decimal(food_metrics.get('map', 0))}")
    else:
        print("WARNING: No food metrics available")
    
    # Summary
    print("\n" + "=" * 80)
    print("SUMMARY")
    print("=" * 80)
    
    # Calculate average metrics across all types (only MAP and NDCG)
    avg_ndcg = {}
    avg_map = 0.0
    
    # Calculate average MAP
    maps = []
    if venue_metrics:
        maps.append(venue_metrics.get('map', 0))
    if theme_metrics:
        maps.append(theme_metrics.get('map', 0))
    if food_metrics:
        maps.append(food_metrics.get('map', 0))
    
    if maps:
        avg_map = sum(maps) / len(maps)
    
    # Calculate average NDCG@K
    for k in k_values:
        ndcgs = []
        
        if venue_metrics:
            ndcgs.append(venue_metrics.get(f'ndcg@{k}', 0))
        
        if theme_metrics:
            ndcgs.append(theme_metrics.get(f'ndcg@{k}', 0))
        
        if food_metrics:
            ndcgs.append(food_metrics.get(f'ndcg@{k}', 0))
        
        if ndcgs:
            avg_ndcg[k] = sum(ndcgs) / len(ndcgs)
    
    # Display summary metrics
    if avg_ndcg:
        print("\nAverage NDCG@K (across venues, themes, food):")
        for k in k_values:
            if k in avg_ndcg:
                print(f"   NDCG@{k}: {format_decimal(avg_ndcg[k])}")
    
    # Average MAP (Mean Average Precision)
    if avg_map > 0:
        print(f"\nAverage MAP (Mean Average Precision): {format_decimal(avg_map)}")
    
    print("\n" + "=" * 80)
    print("Report generated successfully!")
    print("=" * 80)
    
    # Save to file if requested
    if output_file:
        with open(output_file, 'w', encoding='utf-8') as f:
            json.dump(results, f, indent=2, default=str)
        print(f"\nFull report saved to: {output_file}")
    
    return results


if __name__ == "__main__":
    import sys
    
    # Parse command line arguments
    k_values = [3, 5, 10]
    output_file = None
    
    if len(sys.argv) > 1:
        # Parse K values from command line: python generate_accuracy_report.py 3,5,10
        try:
            k_values = [int(k.strip()) for k in sys.argv[1].split(',')]
        except ValueError:
            print("Invalid K values. Using default: 3, 5, 10")
    
    if len(sys.argv) > 2:
        # Output file: python generate_accuracy_report.py 3,5,10 report.json
        output_file = sys.argv[2]
    
    # Generate report
    generate_accuracy_report(k_values=k_values, output_file=output_file)

