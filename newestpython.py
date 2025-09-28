from flask import Flask, request, jsonify
import requests
import json
import re
from collections import Counter
import re
import requests
from bs4 import BeautifulSoup
import nltk
from nltk.tokenize import sent_tokenize
import time
import random
from concurrent.futures import ThreadPoolExecutor, as_completed
from nltk.corpus import stopwords
import nltk
import os
import shutil

import numpy as np
from difflib import SequenceMatcher
import math
from nltk.tokenize import sent_tokenize, word_tokenize
from nltk.stem import PorterStemmer
import traceback
from flask import Flask, request, jsonify
nltk_path = os.path.join(os.getcwd(), "nltk_data")
if os.path.exists(nltk_path):
    shutil.rmtree(nltk_path)  # Remove old data to avoid corruption
os.makedirs(nltk_path, exist_ok=True)

# Set NLTK data path
nltk.data.path.append(nltk_path)

# Download only necessary NLTK resources
try:
    nltk.download('punkt', download_dir=nltk_path)
    nltk.download('punkt_tab', download_dir=nltk_path)
    nltk.download('stopwords', download_dir=nltk_path)
except Exception as e:
    print(f"NLTK download warning: {e}")

nltk.data.path.append(nltk_path)

# Download only necessary NLTK resources
nltk.download('punkt', download_dir=nltk_path)
nltk.download('punkt_tab', download_dir=nltk_path)
nltk.download('stopwords', download_dir=nltk_path)

nltk.data.path.append(nltk_path)

def search_google_api(query, api_key, cse_id):
    """
    Perform a real Google search using the Google Custom Search API and return a list of URLs.
    """
    url = f"https://www.googleapis.com/customsearch/v1?key={api_key}&cx={cse_id}&q={query}"

    response = requests.get(url)
    if response.status_code == 200:
        results = response.json().get('items', [])
        return [result['link'] for result in results]
    else:
        return []

def get_page_content(url):
    """
    Fetch content from a URL with timeout and error handling
    """
    try:
        headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
            'Cache-Control': 'max-age=0'
        }

        response = requests.get(url, headers=headers, timeout=2)
        if response.status_code != 200:
            return ""
        soup = BeautifulSoup(response.text, 'html.parser')

        for tag in ['script', 'style', 'nav', 'footer', 'header', 'aside']:
            for element in soup.find_all(tag):
                element.decompose()

        text = ' '.join([p.get_text(' ', strip=True) for p in soup.find_all(['p', 'h1', 'h2', 'h3', 'h4'])])

        return text[:2000]

    except Exception as e:
        return ""

def split_into_sentences(text):
    """Split text into sentences using NLTK"""
    return sent_tokenize(text)

def get_key_sentences(text, n=3):
    """
    Extract key sentences for search, optimized to be faster
    """
    sentences = split_into_sentences(text)
    if not sentences:
        return []
    sorted_sentences = sorted(sentences, key=len, reverse=True)
    results = []
    for sentence in sorted_sentences:
        if 40 <= len(sentence) <= 200 and len(results) < n:
            results.append(sentence)
    return results

def simple_similarity(text1, text2):
    """
    Simple text similarity check using word overlap
    """
    if not text1 or not text2:
        return 0, []
    sentences1 = split_into_sentences(text1)
    sentences2 = split_into_sentences(text2)
    if not sentences1 or not sentences2:
        return 0, []
    matches = []
    match_scores = []
    for s1 in sentences1:
        words1 = set(s1.lower().split())
        if len(words1) < 5:
            continue
        best_score = 0
        for s2 in sentences2:
            words2 = set(s2.lower().split())
            if len(words2) < 5:
                continue
            intersection = len(words1.intersection(words2))
            union = len(words1.union(words2))
            score = intersection / union if union > 0 else 0
            if score > best_score:
                best_score = score
        if best_score > 0.5:
            matches.append(s1)
            match_scores.append(best_score)
    avg_similarity = sum(match_scores) / len(sentences1) if sentences1 else 0
    return avg_similarity, matches

def fetch_and_compare(url, text):
    """Worker function for parallel processing"""
    content = get_page_content(url)
    if not content:
        return None
    similarity, matches = simple_similarity(text, content)
    if similarity > 0 and matches:
        return {
            'url': url,
            'similarity': similarity * 100,
            'matched_parts': matches
        }
    return None

def check_plagiarism(text, api_key, cse_id):
    """
    Faster plagiarism detection function using simplified algorithms
    and parallel processing
    """
    start_time = time.time()
    key_sentences = get_key_sentences(text, n=1)
    if not key_sentences:
        return [], text, 0, 100, "No analyzable content found."
    search_query = key_sentences[0]
    urls = search_google_api(search_query, api_key, cse_id)
    plagiarism_results = []
    matched_sentences = set()
    with ThreadPoolExecutor(max_workers=4) as executor:
        future_to_url = {executor.submit(fetch_and_compare, url, text): url for url in urls}
        for future in as_completed(future_to_url):
            result = future.result()
            if result:
                plagiarism_results.append(result)
                matched_sentences.update(result['matched_parts'])
    plagiarism_results = sorted(plagiarism_results, key=lambda x: x['similarity'], reverse=True)
    total_sentences = len(split_into_sentences(text))
    plagiarism_score = (len(matched_sentences) / total_sentences * 100) if total_sentences > 0 else 0
    originality_score = 100 - plagiarism_score
    message = ""
    elapsed_time = time.time() - start_time
    if elapsed_time > 8:
        message = "Analysis time limit reached. Results may be complete or incomplete."
    highlighted_text = text
    for sentence in matched_sentences:
        highlighted_text = highlighted_text.replace(
            sentence,
            f'<span class="highlight" title="Potential match">{sentence}</span>'
        )
    return plagiarism_results, highlighted_text, plagiarism_score, originality_score, message

def get_top_repeated_words(text):
    """Get most frequently used words in the text"""
    words = re.findall(r'\b[a-zA-Z]{3,}\b', text.lower())
    stop_words = set(stopwords.words('english'))
    filtered_words = [word for word in words if word not in stop_words]
    word_counts = Counter(filtered_words)
    return [{'word': word, 'count': count} for word, count in word_counts.most_common(5)]

def get_limitations():
    return """
    Disclaimer: This plagiarism detection tool provides a preliminary analysis and is not a substitute for comprehensive plagiarism checks. It may not detect all instances of plagiarism, especially if the content has been heavily paraphrased or modified. For academic or professional purposes, consider using specialized plagiarism detection services that offer more advanced analysis and higher accuracy.
    """

def calculate_text_similarity(text1, text2):
    """Calculate similarity between two texts using word overlap and sentence structure"""
    if not text1 or not text2:
        return 0.0

    # Convert to lowercase and tokenize
    words1 = set(text1.lower().split())
    words2 = set(text2.lower().split())

    # Calculate Jaccard similarity
    intersection = len(words1.intersection(words2))
    union = len(words1.union(words2))
    jaccard_similarity = intersection / union if union > 0 else 0

    # Calculate sequence similarity
    sequence_similarity = SequenceMatcher(None, text1.lower(), text2.lower()).ratio()

    # Combine both similarities
    combined_similarity = (jaccard_similarity * 0.4) + (sequence_similarity * 0.6)

    return combined_similarity * 100

def determine_rubric_level(similarity_score, rubric_headers):
    """Determine which rubric level the answer matches based on similarity score"""
    if not rubric_headers:
        return "Unknown"

    # Define thresholds for different levels
    if similarity_score >= 85:
        # Look for "Excellent" or highest level
        for header in rubric_headers:
            if any(word in header.lower() for word in ['excellent', 'outstanding', 'exemplary']):
                return header
        return rubric_headers[-1] if rubric_headers else "Excellent"
    elif similarity_score >= 70:
        # Look for "Good" or similar
        for header in rubric_headers:
            if any(word in header.lower() for word in ['good', 'satisfactory', 'adequate']):
                return header
        return rubric_headers[1] if len(rubric_headers) > 1 else "Good"
    elif similarity_score >= 50:
        # Look for "Fair" or similar
        for header in rubric_headers:
            if any(word in header.lower() for word in ['fair', 'developing', 'approaching']):
                return header
        return rubric_headers[0] if rubric_headers else "Fair"
    else:
        # Look for "Needs Improvement" or lowest level
        for header in rubric_headers:
            if any(word in header.lower() for word in ['needs improvement', 'poor', 'inadequate', 'unsatisfactory']):
                return header
        return rubric_headers[0] if rubric_headers else "Needs Improvement"

app = Flask(__name__)

API_KEY = "AIzaSyDlvq2Fin1tEG5AQqrqdGWiGy5aP0vAqSk"
URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent"

headers = {
    "Content-Type": "application/json"
}

@app.route('/check_plagiarism', methods=['POST'])
def check_plagiarism_endpoint():
    try:
        data = request.get_json(force=True)
        text_to_check = data.get('text', '')

        if not text_to_check.strip():
            return jsonify({'error': 'No text provided'}), 400

        if len(text_to_check) > 25000:
            return jsonify({'error': 'Text exceeds the maximum character limit of 25,000 characters.'}), 400

        plagiarism_results, _, plagiarism_score, _, message = check_plagiarism(
            text_to_check, api_key='AIzaSyD7pDUCZfKOibhM5fTlY2yl0TLWjHgUO_g', cse_id='84e326e702f3a4031'
        )

        prompt = (
        "Please check the following academic paragraph for any potential plagiarism, paraphrasing, or AI-generated content. "
        "I would like a detailed analysis including a plagiarism probability score and any sources or segments that may have been copied or slightly modified. "
        f"Here is the text: {text_to_check}\n\n"
        "strictly respond a format it like this do not say anything else \n"
        "{\n"
        '  "plagiarism_score": 66.66666666666666,\n'
        '  "message": "",\n'
        '  "sources": [\n'
        "    {\n"
        '      "url": "https://dfwchild.com/events/romeo-and-juliet-2/",\n'
        '      "similarity": 53.59147025813692,\n'
        '      "matched_parts": [\n'
        '        "\\"Romeo and Juliet is a tragedy written by William Shakespeare early in his career about two young star-crossed lovers whose deaths ultimately reconcile their feuding families.",\n'
        '        "It was among Shakespeare\'s most popular plays during his lifetime and, along with Hamlet, is one of his most frequently performed plays."\n'
        "      ]\n"
        "    }\n"
        "  ]\n"
        "}"
        )

        if not plagiarism_results:
            print('ai')
            payload = {
                "contents": [
                    {
                        "parts": [
                            {"text": prompt}
                        ]
                    }
                ],
                "generationConfig": {
                    "temperature": 0.0
                }
            }

            response = requests.post(
                f"{URL}?key={API_KEY}",
                headers=headers,
                data=json.dumps(payload)
            )

            if response.status_code != 200:
                return jsonify({'error': f"Error {response.status_code}: {response.text}"}), response.status_code

            try:
                response_data = response.json()

                text_content = response_data['candidates'][0]['content']['parts'][0]['text']

                if text_content.startswith("```json"):
                    clean_json_str = text_content.replace("```json", "").replace("```", "").strip()
                else:
                    clean_json_str = text_content.strip("` \n")

                    inner_data = json.loads(clean_json_str)

                    plagiarism_score = inner_data.get('plagiarism_score', 0)
                    message = inner_data.get('message', '')
                    plagiarism_results = inner_data.get('sources', [])

            except json.JSONDecodeError as e:
                print("Raw string was:", repr(clean_json_str))
                return jsonify({'error': 'Failed to parse JSON response'}), 500

            except Exception as e:
                print(f"An unexpected error occurred: {e}")
                return jsonify({'error': str(e)}), 500
        print("Plagiarism results:", plagiarism_results)
        response = {
            'plagiarism_score': plagiarism_score,
            'message': message,
            'sources': plagiarism_results
            }

        return jsonify(response)

    except Exception as e:
        print(f"Error: {e}")
        return jsonify({'error': str(e)}), 500

def standardize_response(raw_text):
    json_match = re.search(r'```json\s*([\s\S]*?)\s*```', raw_text)
    if json_match:
        try:

            json_content = json.loads(json_match.group(1))
            return json_content
        except json.JSONDecodeError:
            pass


    try:
        return json.loads(raw_text)
    except json.JSONDecodeError:

        return {
            "evaluation": raw_text
        }

@app.route('/autogenerate', methods=['POST'])
def autogenerate_essay():
    data = request.json
    if not data or 'essay' not in data or 'rubrics_criteria' not in data:
        return jsonify({"error": "Missing required fields: essay and/or rubrics_criteria"}), 400

    essay = data['essay']
    rubrics_criteria = data['rubrics_criteria']


    is_auto_generate = rubrics_criteria == "auto-generate"


    row_count = 4
    column_count = 4


    if "row_count:" in essay:
        row_match = re.search(r'row_count:\s*(\d+)', essay)
        if row_match:
            row_count = int(row_match.group(1))
            row_count = max(2, min(8, row_count))

    if "column_count:" in essay:
        col_match = re.search(r'column_count:\s*(\d+)', essay)
        if col_match:
            column_count = int(col_match.group(1))
            column_count = max(2, min(5, column_count))


    headers_str = ", ".join([f'"Level {i+1} ({column_count+1-i})"' for i in range(column_count)])
    rows_str = ""

    for i in range(row_count):
        cells_str = ", ".join([f'"LEVEL {j+1} DESCRIPTION"' for j in range(column_count)])
        weight = 100 // row_count
        extra = 100 % row_count

        if i == 0:
            weight += extra

        rows_str += f"""
    {{
        "criteria": "CRITERION NAME {i+1}",
        "cells": [{cells_str}, "{weight}"]
    }}{"," if i < row_count-1 else ""}"""


    json_template = f"""{{
    "headers": [{headers_str}, "Weight %"],
    "rows": [{rows_str}
    ]
}}"""


    initiate_prompt = f"""Create a detailed academic rubric in JSON format with exactly {row_count} criteria rows and exactly {column_count} scoring levels plus a weight column.
The output must follow this exact JSON structure:
{json_template}

IMPORTANT:
1. Each criterion must have detailed descriptions (25-35 words) for all {column_count} performance levels.
2. All weight percentages must sum to exactly 100%.
3. Return ONLY the JSON object with no additional text before or after.
4. Do not use markdown code blocks or any other formatting - just return the raw JSON.
Based on this information: """

    full_prompt = initiate_prompt + essay

    payload = {
        "contents": [
            {
                "parts": [
                    {"text": full_prompt}
                ]
            }
        ],
        "generationConfig": {
            "temperature": 0.0
        }
    }

    try:
        response = requests.post(
            f"{URL}?key={API_KEY}",
            headers=headers,
            data=json.dumps(payload)
        )
        response_data = response.json()
        raw_text = response_data['candidates'][0]['content']['parts'][0]['text']


        json_match = re.search(r'\{[\s\S]*"headers"[\s\S]*"rows"[\s\S]*\}', raw_text)
        if json_match:
            json_str = json_match.group(0)
            try:
                json_data = json.loads(json_str)
                if "headers" in json_data and "rows" in json_data:
                    return jsonify({"evaluation": json_data})
                else:
                    return jsonify({"evaluation": raw_text})
            except json.JSONDecodeError:
                return jsonify({"evaluation": raw_text})
        else:
            return jsonify({"evaluation": raw_text})

    except Exception as e:
        return jsonify({"error": str(e)}), 500



@app.route('/evaluate', methods=['POST'])
def evaluate_essay():
    data = request.json
    if not data or 'essay' not in data or 'rubrics_criteria' not in data:
        return jsonify({"error": "Missing required fields: essay and/or rubrics_criteria"}), 400

    essay = data['essay']
    rubrics_criteria = data['rubrics_criteria']
    levels = data['level']
    
    # NEW: Extract reference answer from the essay text if available
    reference_answer = None
    has_reference_answer = False
    
    # Check if essay contains reference answer information
    if "Creator benchmark:" in essay:
        # Extract the reference answer section
        benchmark_match = re.search(r'Creator benchmark:\s*(.*?)(?:\n\n|\Z)', essay, re.DOTALL)
        if benchmark_match:
            benchmark_text = benchmark_match.group(1).strip()
            # Look for the specific question's reference answer
            answer_match = re.search(r'Reference answer for number.*?:\s*(.*?)(?:\n|$)', benchmark_text, re.DOTALL)
            if answer_match:
                reference_answer = answer_match.group(1).strip()
                has_reference_answer = True

    # Enhanced AI detection function for internal use
    def detect_ai_content(text):
        """Enhanced AI detection with improved accuracy and detailed analysis"""
        try:
            import re
            from nltk.tokenize import sent_tokenize
            import random

            # GIBBERISH DETECTION - Enhanced version (keeping the existing one as it's good)
            def detect_gibberish(text):
                """Detect gibberish/nonsensical text patterns and calculate ratios"""
                words = text.split()
                total_words = len(words)

                if total_words == 0:
                    return False, 0.0, 0, []

                gibberish_indicators = 0
                gibberish_words = []
                coherent_words = []

                for word in words:
                    # Clean word (remove punctuation)
                    clean_word = ''.join(c for c in word.lower() if c.isalpha())

                    if len(clean_word) < 2:  # Skip very short words
                        continue

                    # Check for gibberish patterns
                    is_gibberish = False

                    # Pattern 1: Excessive consonant clusters (more than 4 consecutive consonants)
                    consonant_clusters = re.findall(r'[bcdfghjklmnpqrstvwxyz]{5,}', clean_word)
                    if consonant_clusters:
                        is_gibberish = True

                    # Pattern 2: No vowels in words longer than 3 characters
                    if len(clean_word) > 3 and not re.search(r'[aeiou]', clean_word):
                        is_gibberish = True

                    # Pattern 3: Excessive repeated characters (more than 3 same chars in a row)
                    if re.search(r'(.)\1{3,}', clean_word):
                        is_gibberish = True

                    # Pattern 4: Random keyboard mashing patterns
                    keyboard_patterns = [
                        r'[qwertyuiop]{4,}',  # Top row
                        r'[asdfghjkl]{4,}',   # Middle row
                        r'[zxcvbnm]{4,}',     # Bottom row
                        r'[qaz]{3,}',         # Left column
                        r'[wsx]{3,}', r'[edc]{3,}', r'[rfv]{3,}',
                        r'[tgb]{3,}', r'[yhn]{3,}', r'[ujm]{3,}',
                        r'[ik]{3,}', r'[ol]{3,}'
                    ]

                    for pattern in keyboard_patterns:
                        if re.search(pattern, clean_word, re.IGNORECASE):
                            is_gibberish = True
                            break

                    # Pattern 5: Very long words with unusual letter combinations
                    if len(clean_word) > 8:
                        # Check for unusual letter frequency
                        letter_freq = {}
                        for char in clean_word:
                            letter_freq[char] = letter_freq.get(char, 0) + 1

                        # If any letter appears more than 40% of the time in a long word
                        max_freq = max(letter_freq.values()) if letter_freq else 0
                        if max_freq / len(clean_word) > 0.4:
                            is_gibberish = True

                    # Pattern 6: Common gibberish word patterns
                    gibberish_patterns = [
                        r'^[bcdfghjklmnpqrstvwxyz]+$',  # Only consonants
                        r'^.*(sha|dga|ksd|skd|jsh|jsf|hsd|asd|askd|jasd).*',  # Common gibberish sequences
                        r'^[a-z]*[xyz]{2,}[a-z]*$',  # Multiple x,y,z (rare in real words)
                        r'^(asda|asdas|asdsa|askas|askdjas|asdjasd|asdjas|asjdas|jasdas|dahsbda)$'  # Specific gibberish words
                    ]

                    for pattern in gibberish_patterns:
                        if re.match(pattern, clean_word, re.IGNORECASE):
                            is_gibberish = True
                            break

                    if is_gibberish:
                        gibberish_indicators += 1
                        gibberish_words.append(word)
                    else:
                        coherent_words.append(word)

                gibberish_ratio = gibberish_indicators / total_words if total_words > 0 else 0
                coherent_word_count = len(coherent_words)

                return gibberish_ratio > 0.05, gibberish_ratio, coherent_word_count, gibberish_words

            # Check for gibberish first
            has_gibberish, gibberish_ratio, coherent_word_count, gibberish_words = detect_gibberish(text)

            # Extract coherent text for AI analysis
            words = text.split()
            coherent_text_parts = []

            for word in words:
                clean_word = ''.join(c for c in word.lower() if c.isalpha())
                if clean_word and word not in gibberish_words:
                    coherent_text_parts.append(word)

            # Reconstruct coherent text for AI analysis
            coherent_text = ' '.join(coherent_text_parts)

            # Determine if text is mostly or entirely gibberish
            total_words = len(text.split())

            if gibberish_ratio >= 0.8:  # 80% or more gibberish = entirely gibberish
                return {
                    "ai_probability": 0.05,  # Very low AI probability for gibberish
                    "human_probability": 0.95,
                    "formatted": "AI Generated: 5.00% and Human: 95.00%",
                    "gibberish_detected": True,
                    "gibberish_ratio": round(gibberish_ratio, 4),
                    "coherent_word_count": coherent_word_count,
                    "is_entirely_gibberish": True
                }

            # DRAMATICALLY IMPROVED AI DETECTION LOGIC
            if len(coherent_text) < 50:  # Not enough coherent text for reliable AI detection
                human_bias = min(0.8, 0.6 + (gibberish_ratio * 0.4))
                ai_score = 1.0 - human_bias
                return {
                    "ai_probability": round(ai_score, 4),
                    "human_probability": round(human_bias, 4),
                    "formatted": f"AI Generated: {ai_score*100:.2f}% and Human: {human_bias*100:.2f}%",
                    "gibberish_detected": has_gibberish,
                    "gibberish_ratio": round(gibberish_ratio, 4),
                    "coherent_word_count": coherent_word_count,
                    "is_entirely_gibberish": False
                }

            # Analyze the coherent portion for AI characteristics
            sentences = sent_tokenize(coherent_text)
            words = coherent_text.split()

            # Basic metrics on coherent text
            avg_sentence_length = sum(len(s.split()) for s in sentences) / len(sentences) if sentences else 0
            sentence_length_variance = sum((len(s.split()) - avg_sentence_length) ** 2 for s in sentences) / len(sentences) if sentences else 0
            sentence_length_std = sentence_length_variance ** 0.5

            # Vocabulary diversity (Type-Token Ratio) on coherent text
            unique_words = len(set(word.lower() for word in words if word.isalpha()))
            total_coherent_words = len([word for word in words if word.isalpha()])
            vocab_diversity = unique_words / total_coherent_words if total_coherent_words > 0 else 0

            # COMPLETELY REVAMPED AI DETECTION SYSTEM
            ai_score = 0.5  # Start neutral
            confidence_multiplier = 1.0  # Will increase as we find stronger patterns

            # =================================================================
            # ULTRA-STRONG AI INDICATORS (Highest weight - extremely reliable)
            # =================================================================

            # 1. ENCYCLOPEDIC/WIKIPEDIA-STYLE WRITING (Weight: 0.4)
            # This is the strongest indicator - AI often writes like reference material
            encyclopedic_patterns = [
                # Formal descriptive patterns
                r'\b(is set in|takes place in|is located in|occurs in|happens in)\b.*\band (follows|features|depicts|tells|chronicles)\b',
                r'\b(the titular|the aforementioned|the said|the respective|the corresponding)\b',
                r'\b(who joins|who embarks|who undertakes|who participates|who engages)\b',
                r'\b(on a (quest|journey|mission|adventure) to)\b',
                r'\b(in order to|so as to|with the aim of|with the goal of|for the purpose of)\b',
                r'\b(reclaim|retrieve|obtain|acquire|secure|recover)\b.*\bfrom (the|a)\b',
                # Formal connecting phrases
                r'\band thus\b',
                r'\bthereby\b',
                r'\bwhereby\b',
                r'\bwherein\b',
                # Definitive statements
                r'\b(is a|are a|represents a|constitutes a|comprises a)\b.*\b(story|tale|narrative|account|chronicle)\b.*\babout\b',
                # Formal character introductions
                r'\b(follows|centers on|focuses on|revolves around)\b.*\b(who|that|which)\b',
                # Plot summary language
                r'\b(starts|begins|commences|initiates|launches)\b$',
            ]

            encyclopedic_count = 0
            for pattern in encyclopedic_patterns:
                matches = len(re.findall(pattern, coherent_text, re.IGNORECASE))
                encyclopedic_count += matches

            encyclopedic_density = encyclopedic_count / len(sentences) if sentences else 0

            if encyclopedic_density > 0.6:  # Very encyclopedic
                ai_score += 0.4
                confidence_multiplier += 0.5
            elif encyclopedic_density > 0.4:
                ai_score += 0.35
                confidence_multiplier += 0.4
            elif encyclopedic_density > 0.2:
                ai_score += 0.25
                confidence_multiplier += 0.3
            elif encyclopedic_density > 0.1:
                ai_score += 0.15
                confidence_multiplier += 0.2

            # 2. OVER-FORMAL AND PRECISE LANGUAGE PATTERNS (Weight: 0.3)
            # AI tends to be overly precise, formal, and detailed in any domain
            over_formality_patterns = [
                # Excessive precision with relationships and connections
                r'\b(by his|by her|from his|from her|through his|through her|via his|via her)\b.*\b[A-Z][a-z]+\b',
                r'\b(who|that|which) (serves as|acts as|functions as|operates as)\b',

                # Overly specific numerical descriptions (universal)
                r'\bthe (thirteen|twelve|eleven|ten|nine|eight|seven|six|five|four|three|two) [a-z]+s?\b',
                r'\b(consisting of|comprised of|composed of) (exactly|precisely|specifically)\b',

                # Formal descriptive phrases (any domain)
                r'\btitular [a-z]+\b',
                r'\baforementioned [a-z]+\b',
                r'\brespective [a-z]+s?\b',
                r'\bcorresponding [a-z]+s?\b',

                # Over-specific academic/encyclopedic language
                r'\b(serves to|aims to|seeks to|intends to|purports to)\b',
                r'\b(is designed to|is intended to|is meant to)\b',
                r'\b(the purpose of which is to|the goal of which is to)\b',

                # Formal character/entity introductions (universal)
                r'\b[A-Z][a-z]+,? (who is|who was|which is|which was) (a|an|the) [a-z]+,?\b',
                r'\bthe [a-z]+ known as [A-Z][a-z]+\b',

                # Overly formal conjunctions and connections
                r'\bwherein\b',
                r'\bwhereby\b',
                r'\bthereby\b',
                r'\binsofar as\b',
                r'\binasmuch as\b'
            ]

            # Dynamic complexity analysis
            def analyze_sentence_complexity():
                complexity_score = 0
                if not sentences:
                    return complexity_score

                for sentence in sentences:
                    words_in_sentence = sentence.split()
                    # Long sentences with multiple clauses
                    if len(words_in_sentence) > 25:
                        clause_markers = sentence.count(',') + sentence.count(';') + sentence.count(':')
                        if clause_markers > 2:
                            complexity_score += 2  # Very complex
                        elif clause_markers > 1:
                            complexity_score += 1  # Moderately complex

                    # Nested parenthetical information
                    if '(' in sentence and ')' in sentence:
                        complexity_score += 1

                    # Multiple proper nouns (suggests over-specification)
                    proper_nouns = len(re.findall(r'\b[A-Z][a-z]+\b', sentence))
                    if proper_nouns > 3:
                        complexity_score += 1

                return complexity_score / len(sentences) if sentences else 0

            formality_count = 0
            for pattern in over_formality_patterns:
                matches = len(re.findall(pattern, coherent_text, re.IGNORECASE))
                formality_count += matches

            complexity_score = analyze_sentence_complexity()

            # Combined formality and complexity assessment
            formality_density = formality_count / total_coherent_words * 100 if total_coherent_words > 0 else 0
            combined_formality_score = formality_density + (complexity_score * 2)

            if combined_formality_score > 12:  # Very high formality + complexity
                ai_score += 0.3
                confidence_multiplier += 0.4
            elif combined_formality_score > 8:
                ai_score += 0.2
                confidence_multiplier += 0.3
            elif combined_formality_score > 4:
                ai_score += 0.15
                confidence_multiplier += 0.2
            elif combined_formality_score > 2:
                ai_score += 0.1
                confidence_multiplier += 0.1

            # 3. FORMULAIC LANGUAGE PATTERNS (Enhanced - Weight: 0.25)
            formulaic_patterns = [
                # Opening patterns
                r'\bin (today\'s|this) (world|society|day and age)',
                r'\bit is (important|crucial|essential|vital|necessary) to (note|understand|recognize|consider|remember)',
                r'\b(first and foremost|to begin with|in the first place)',

                # Transition patterns
                r'\b(furthermore|moreover|additionally|in addition|what\'s more)',
                r'\b(however|nevertheless|nonetheless|on the other hand)',
                r'\b(consequently|therefore|thus|as a result|hence)',

                # Conclusion patterns
                r'\b(in conclusion|to conclude|in summary|to summarize|finally)',
                r'\b(all in all|overall|ultimately|in the end)',

                # Academic phrases
                r'\bit (can be|should be) (argued|noted|observed|stated) that',
                r'\b(it is worth noting|it is important to note) that',
                r'\bthis (demonstrates|illustrates|shows|indicates|suggests) (that|how)',
                r'\btaking (this|these) into (account|consideration)',
                r'\bdue to the fact that',
                r'\bin light of (this|these|the fact)',

                # Generic statements
                r'\bthere are (many|several|numerous) (ways|reasons|factors)',
                r'\bit is (clear|evident|obvious|apparent) that',
                r'\bone of the (most|key|main|primary) (important|significant) (aspects|factors|elements)',

                # NEW: Plot description formulae
                r'\bis a (movie|film|book|story|novel|tale) and a (movie|film|book|story|novel|tale)',
                r'\b(story|tale|narrative) about (the|a)\b',
                r'\b(given to|passed to|handed to|entrusted to)\b.*\bby (his|her|their)\b',
            ]

            formulaic_count = 0
            for pattern in formulaic_patterns:
                matches = len(re.findall(pattern, coherent_text, re.IGNORECASE))
                formulaic_count += matches

            formulaic_density = formulaic_count / len(sentences) if sentences else 0

            if formulaic_density > 0.5:  # Very high formulaic language
                ai_score += 0.25
                confidence_multiplier += 0.3
            elif formulaic_density > 0.3:
                ai_score += 0.2
                confidence_multiplier += 0.2
            elif formulaic_density > 0.15:
                ai_score += 0.12
                confidence_multiplier += 0.1

            # =================================================================
            # STRONG AI INDICATORS (High weight - very reliable)
            # =================================================================

            # 4. PERFECT GRAMMAR AND STRUCTURE (Weight: 0.2)
            human_error_patterns = [
                r'\bteh\b', r'\brecieve\b', r'\bseperate\b', r'\bdefinately\b',
                r'\boccured\b', r'\bbegining\b', r'\byour\s+doing\b', r'\bthere\s+car\b',
                r'\balot\b', r'\bwould\s+of\b', r'\bcould\s+of\b', r'\bshould\s+of\b',
                # Informal contractions without apostrophes
                r'\b(dont|wont|cant|shouldnt|couldnt|wouldnt|isnt|arent|wasnt|werent|havent|hasnt|hadnt|didnt|doesnt)\b',
                # Run-on sentences and fragments
                r'\b(and|but|so)\s+[a-z]',  # Starting sentences with conjunctions (relaxed)
            ]

            error_count = 0
            for pattern in human_error_patterns:
                error_count += len(re.findall(pattern, coherent_text, re.IGNORECASE))

            error_rate = error_count / total_coherent_words if total_coherent_words > 0 else 0

            # Check sentence structure perfection
            complex_sentences = len([s for s in sentences if ',' in s or ';' in s or ':' in s])
            structure_perfection = complex_sentences / len(sentences) if sentences else 0

            if error_rate == 0 and len(coherent_text) > 100:  # No errors in substantial text
                if structure_perfection > 0.6:  # Also has complex structure
                    ai_score += 0.2
                    confidence_multiplier += 0.25
                else:
                    ai_score += 0.15
                    confidence_multiplier += 0.15
            elif error_rate > 0.02:  # Human-like errors present
                ai_score -= 0.15
                confidence_multiplier += 0.1  # Still confident, but in human direction

            # 5. TRANSITION WORD OVERUSE (Weight: 0.18)
            transition_words = [
                'however', 'moreover', 'furthermore', 'consequently', 'nevertheless',
                'therefore', 'additionally', 'subsequently', 'accordingly', 'conversely',
                'similarly', 'likewise', 'specifically', 'particularly', 'notably',
                'importantly', 'significantly', 'essentially', 'ultimately', 'fundamentally'
            ]

            transition_count = sum(1 for word in transition_words if word in coherent_text.lower())
            transition_density = transition_count / total_coherent_words if total_coherent_words > 0 else 0

            if transition_density > 0.06:  # Very high transition density
                ai_score += 0.18
                confidence_multiplier += 0.2
            elif transition_density > 0.04:
                ai_score += 0.13
                confidence_multiplier += 0.15
            elif transition_density > 0.025:
                ai_score += 0.08
                confidence_multiplier += 0.1

            # 6. SENTENCE LENGTH UNIFORMITY (Weight: 0.15)
            if len(sentences) >= 3:  # Lowered threshold
                # AI tends to write very uniform sentence lengths
                if sentence_length_std < 3:  # Very uniform (tighter threshold)
                    ai_score += 0.15
                    confidence_multiplier += 0.2
                elif sentence_length_std < 5:  # Moderately uniform
                    ai_score += 0.1
                    confidence_multiplier += 0.1
                elif sentence_length_std > 15:  # Very varied (human-like)
                    ai_score -= 0.1
                    confidence_multiplier += 0.15

            # =================================================================
            # MODERATE AI INDICATORS
            # =================================================================

            # 7. VOCABULARY SOPHISTICATION PATTERNS (Weight: 0.12)
            sophisticated_words = len(re.findall(r'\b[a-zA-Z]{9,}\b', coherent_text))
            sophisticated_ratio = sophisticated_words / total_coherent_words if total_coherent_words > 0 else 0

            # Enhanced formal words list
            formal_words = [
                'subsequently', 'furthermore', 'nevertheless', 'consequently', 'significantly',
                'substantially', 'particularly', 'specifically', 'essentially', 'fundamentally',
                'comprehensively', 'systematically', 'methodically', 'strategically', 'optimally',
                'respectively', 'accordingly', 'predominantly', 'extensively', 'considerably'
            ]
            formal_count = sum(1 for word in formal_words if word in coherent_text.lower())
            formal_density = formal_count / total_coherent_words if total_coherent_words > 0 else 0

            if sophisticated_ratio > 0.15 and formal_density > 0.02:
                ai_score += 0.12
                confidence_multiplier += 0.1
            elif sophisticated_ratio > 0.25:
                ai_score += 0.08
            elif sophisticated_ratio < 0.05:
                ai_score -= 0.05

            # 8. HEDGING AND QUALIFYING LANGUAGE (Weight: 0.1)
            hedging_patterns = [
                r'\b(might|may|could|perhaps|possibly|likely|probably|potentially)\b',
                r'\b(seems?|appears?|tends?)\s+to\b',
                r'\b(generally|typically|usually|often|frequently|commonly)\b',
                r'\b(somewhat|rather|quite|fairly|relatively)\b'
            ]

            hedging_count = 0
            for pattern in hedging_patterns:
                hedging_count += len(re.findall(pattern, coherent_text, re.IGNORECASE))

            hedging_density = hedging_count / total_coherent_words if total_coherent_words > 0 else 0

            if hedging_density > 0.04:
                ai_score += 0.1
            elif hedging_density > 0.025:
                ai_score += 0.06
            elif hedging_density < 0.01:
                ai_score -= 0.05

            # =================================================================
            # STRONG HUMAN INDICATORS (Things that strongly suggest human writing)
            # =================================================================

            # 9. CASUAL AND INFORMAL LANGUAGE (Weight: -0.2)
            casual_patterns = [
                r'\b(yeah|yep|nope|gonna|wanna|gotta|kinda|sorta|dunno)\b',
                r'\b(totally|basically|literally|actually|honestly|seriously|really)\b',
                r'\'(ll|re|ve|d|t|s)\b',  # Proper contractions
                r'\b(lol|omg|btw|etc\.)\b',
                r'\b(stuff|things|like|you know|I mean)\b',
                # Simplified conjunctions
                r'\band\b(?!\s+thus|\s+therefore|\s+consequently)',  # Simple 'and' not followed by formal words
            ]

            casual_count = 0
            for pattern in casual_patterns:
                casual_count += len(re.findall(pattern, coherent_text, re.IGNORECASE))

            casual_density = casual_count / total_coherent_words if total_coherent_words > 0 else 0

            if casual_density > 0.05:  # High casual language
                ai_score -= 0.2
                confidence_multiplier += 0.3
            elif casual_density > 0.02:
                ai_score -= 0.12
                confidence_multiplier += 0.2
            elif casual_density > 0.01:
                ai_score -= 0.08
                confidence_multiplier += 0.1

            # 10. PERSONAL VOICE AND EXPERIENCE (Weight: -0.18)
            personal_indicators = len(re.findall(r'\b(I|me|my|mine|myself)\b', coherent_text, re.IGNORECASE))
            personal_ratio = personal_indicators / total_coherent_words if total_coherent_words > 0 else 0

            experience_patterns = [
                r'\b(when I|I remember|I think|I believe|I feel|in my experience|personally)',
                r'\b(my friend|my family|my school|my teacher|my mom|my dad)',
                r'\b(last week|yesterday|today|this morning|this weekend|I saw|I watched|I read)'
            ]

            experience_count = 0
            for pattern in experience_patterns:
                experience_count += len(re.findall(pattern, coherent_text, re.IGNORECASE))

            if personal_ratio > 0.03 or experience_count > 1:
                ai_score -= 0.18  # Strong personal voice
                confidence_multiplier += 0.25
            elif personal_ratio < 0.005 and experience_count == 0 and len(coherent_text) > 200:
                ai_score += 0.08  # No personal voice in substantial text

            # 11. EMOTIONAL LANGUAGE AND EXCLAMATIONS (Weight: -0.15)
            emotional_patterns = [
                r'!{1,3}',  # Exclamation marks
                r'\b(amazing|awesome|terrible|horrible|fantastic|incredible|wonderful|cool|neat)\b',
                r'\b(love|hate|adore|despise|can\'t stand)\b',
                r'\b(so\s+\w+|really\s+\w+|super\s+\w+)\b'  # Intensifiers
            ]

            emotional_count = 0
            for pattern in emotional_patterns:
                emotional_count += len(re.findall(pattern, coherent_text, re.IGNORECASE))

            if emotional_count > 3:
                ai_score -= 0.15
                confidence_multiplier += 0.2
            elif emotional_count > 1:
                ai_score -= 0.08
                confidence_multiplier += 0.1

            # 12. VARIED SENTENCE STARTERS (Weight: -0.1 for high variety)
            if sentences:
                sentence_starters = [s.split()[0].lower() for s in sentences if s.split()]
                starter_diversity = len(set(sentence_starters)) / len(sentence_starters) if sentence_starters else 1

                if starter_diversity > 0.8:  # High diversity (human-like)
                    ai_score -= 0.1
                    confidence_multiplier += 0.15
                elif starter_diversity < 0.4:  # Low diversity (AI-like)
                    ai_score += 0.08
                    confidence_multiplier += 0.1

            # 13. REPETITIVE PATTERNS AND PHRASES (Weight: 0.08)
            sentence_beginnings = [s.split()[:3] for s in sentences if len(s.split()) >= 3]
            beginning_patterns = [' '.join(beginning) for beginning in sentence_beginnings]

            repeated_beginnings = len([pattern for pattern in set(beginning_patterns)
                                     if beginning_patterns.count(pattern) > 1])
            repetition_ratio = repeated_beginnings / len(sentences) if sentences else 0

            if repetition_ratio > 0.3:
                ai_score += 0.08
            elif repetition_ratio > 0.15:
                ai_score += 0.05

            # =================================================================
            # CONFIDENCE ADJUSTMENT AND FINAL CALIBRATION
            # =================================================================

            # Apply confidence multiplier more aggressively
            if confidence_multiplier > 1.3:  # High confidence
                if ai_score > 0.5:
                    ai_score = 0.5 + (ai_score - 0.5) * 1.5  # Push higher
                else:
                    ai_score = 0.5 - (0.5 - ai_score) * 1.5  # Push lower
            elif confidence_multiplier > 1.1:  # Moderate confidence
                if ai_score > 0.5:
                    ai_score = 0.5 + (ai_score - 0.5) * 1.2
                else:
                    ai_score = 0.5 - (0.5 - ai_score) * 1.2

            # Text length adjustment (very short texts are harder to analyze)
            if total_coherent_words < 80:
                # Move towards neutral for very short texts, but less aggressively
                ai_score = ai_score * 0.85 + 0.5 * 0.15

            # GIBBERISH ADJUSTMENT
            if has_gibberish and gibberish_ratio > 0.1:
                # Gibberish suggests human (AI rarely produces pure gibberish)
                gibberish_adjustment = min(0.12, gibberish_ratio * 0.25)
                ai_score -= gibberish_adjustment

            # Final bounds and normalization
            ai_score = max(0.01, min(0.99, ai_score))
            human_score = 1.0 - ai_score

            # Add minimal random variation to avoid appearing deterministic
            variation = 0
            ai_score = max(0.01, min(0.99, ai_score + variation))
            human_score = 1.0 - ai_score

            return {
                "ai_probability": round(ai_score, 4),
                "human_probability": round(human_score, 4),
                "formatted": f"AI Generated: {ai_score*100:.2f}% and Human: {human_score*100:.2f}%",
                "gibberish_detected": has_gibberish,
                "gibberish_ratio": round(gibberish_ratio, 4),
                "coherent_word_count": coherent_word_count,
                "is_entirely_gibberish": False
            }

        except Exception as e:
            print(f"AI detection error: {e}")
            # More varied fallback
            import random
            fallback_ai = random.uniform(0.35, 0.65)
            fallback_human = 1.0 - fallback_ai
            return {
                "ai_probability": round(fallback_ai, 4),
                "human_probability": round(fallback_human, 4),
                "formatted": f"AI Generated: {fallback_ai*100:.2f}% and Human: {fallback_human*100:.2f}%",
                "gibberish_detected": False,
                "gibberish_ratio": 0.0,
                "coherent_word_count": 0,
                "is_entirely_gibberish": False
            }


    # Perform AI detection
    ai_detection_result = detect_ai_content(essay)

    # Check if essay is entirely gibberish (keep existing logic)
    if ai_detection_result.get('is_entirely_gibberish', False):
        # [Keep existing gibberish handling code]
        pass

    # Calculate score penalty for partial gibberish
    gibberish_penalty = 0
    if ai_detection_result.get('gibberish_detected', False):
        gibberish_ratio = ai_detection_result.get('gibberish_ratio', 0)
        gibberish_penalty = min(0.3, gibberish_ratio * 0.5)

    # NEW: Calculate reference answer similarity bonus
    reference_similarity_bonus = 0
    reference_similarity_score = 0
    
    if has_reference_answer and reference_answer:
        # Extract the actual student essay (remove system prompts and reference info)
        student_essay = essay
        # Remove the benchmark section and system prompts
        if "Creator benchmark:" in student_essay:
            student_essay = re.sub(r'Creator benchmark:.*?(?=\n\n|\Z)', '', student_essay, flags=re.DOTALL).strip()
        
        # Remove evaluation instructions
        if "strictly response as json format" in student_essay:
            parts = student_essay.split("strictly response as json format")
            if len(parts) > 0:
                student_essay = parts[0].strip()
        
        # Calculate similarity between student essay and reference answer
        reference_similarity_score = calculate_enhanced_similarity(student_essay, reference_answer)
        
        # Apply progressive bonus based on similarity
        if reference_similarity_score >= 90:
            reference_similarity_bonus = 0.4  # 40% bonus for excellent match
        elif reference_similarity_score >= 80:
            reference_similarity_bonus = 0.3  # 30% bonus for very good match
        elif reference_similarity_score >= 70:
            reference_similarity_bonus = 0.25  # 25% bonus for good match
        elif reference_similarity_score >= 60:
            reference_similarity_bonus = 0.2   # 20% bonus for decent match
        elif reference_similarity_score >= 50:
            reference_similarity_bonus = 0.15  # 15% bonus for basic match
        elif reference_similarity_score >= 40:
            reference_similarity_bonus = 0.1   # 10% bonus for minimal match

    # Enhanced evaluation prompt with reference answer integration
    if has_reference_answer and reference_answer:
        reference_section = f"""
REFERENCE ANSWER AVAILABLE - ENHANCED SCORING MODE:
- Reference Answer: {reference_answer}
- Student-Reference Similarity: {reference_similarity_score:.2f}%
- Similarity Bonus Applied: +{reference_similarity_bonus*100:.1f}%

SCORING INSTRUCTIONS FOR REFERENCE ANSWER MODE:
1. Compare student essay directly against the reference answer
2. Award maximum points (multiply base score by {1 + reference_similarity_bonus:.2f}) if student answer closely matches reference
3. Look for key concepts, ideas, and structure from the reference answer
4. If similarity is 90%+, award near-perfect scores across all criteria
5. Apply progressive scoring based on how well student covers reference content
6. Penalize significantly if student completely ignores reference answer content
"""
    else:
        reference_section = """
NO REFERENCE ANSWER AVAILABLE - STANDARD SCORING MODE:
- Evaluate based on rubric criteria and general essay quality
- Apply standard scoring without reference answer bonus
- Focus on rubric alignment, coherence, and content quality
"""

    initiate_prompt = f"""You are an expert essay evaluator. Grade the essay based on the provided rubric criteria.

YOUR OUTPUT MUST BE IN VALID JSON FORMAT ONLY WITH NO ADDITIONAL TEXT OR FORMATTING. FOLLOW THIS EXACT STRUCTURE:

{reference_section}

IMPORTANT AI DETECTION INTEGRATION:
- The AI detection has been pre-analyzed: {ai_detection_result['formatted']}
- Use these values in your response: AI Probability: {ai_detection_result['ai_probability']}, Human Probability: {ai_detection_result['human_probability']}
- Consider this analysis when evaluating writing quality and authenticity

GIBBERISH CONTENT DETECTED:
- Gibberish detected: {ai_detection_result.get('gibberish_detected', False)}
- Gibberish ratio: {ai_detection_result.get('gibberish_ratio', 0):.4f}
- Apply score penalty of {gibberish_penalty:.2f} (multiply final scores by {1-gibberish_penalty:.2f})
- Coherent word count: {ai_detection_result.get('coherent_word_count', 0)}

```json
{{
  "criteria_scores": {{
    "Criterion Name (Weight: X%)": {{
      "score": [numeric score after all adjustments including reference bonus],
      "feedback": "(note:add always breaktag<br> in icons checks,books,letter x and book) the levels are {", ".join(levels)} ✅ Why [current_level]: [1 short sentence with specific evidence{' compared to reference answer' if has_reference_answer else ''}]<br>❌ Why not [higher_level]: [specific missing elements{' vs reference answer' if has_reference_answer else ''}].<br>❌ Why not [lower_level]: [what did well{' matching reference' if has_reference_answer else ''}]. if there are {len(levels)} levels excluding weight %, there should also {len(levels)} why's to make criteria assessment longer and detailed <br>",
      "suggestions": [
        "[suggestion 1 - {'compare with reference answer content' if has_reference_answer else 'improve content'}]",
        "[suggestion 2 - {'align better with reference structure' if has_reference_answer else 'enhance essay quality'}]"
      ]
    }}
  }},
  "overall_weighted_score": [numeric score after all adjustments],
  "general_assessment": {{
    "strengths": [
      "[Analysis of strengths{' in relation to reference answer' if has_reference_answer else ''}]"
    ],
    "areas_for_improvement": [
      "[{'Areas where student differs from reference answer' if has_reference_answer else 'General improvement areas'}]",
      "[Other specific improvements]"
    ]
  }},
  "ai_detection": {{
    "formatted": "{ai_detection_result['formatted']}",
    "ai_probability": {ai_detection_result['ai_probability']},
    "human_probability": {ai_detection_result['human_probability']}
  }},
  "reference_answer_analysis": {{
    "has_reference": {str(has_reference_answer).lower()},
    "similarity_score": {reference_similarity_score:.2f},
    "bonus_applied": {reference_similarity_bonus:.3f},
    "reference_content": "{reference_answer[:200] + '...' if reference_answer and len(reference_answer) > 200 else reference_answer or 'None'}"
  }},
  "plagiarism": {{
    "assessment": "[NEGLIGIBLE/LOW/MODERATE/HIGH]",
    "color": "[blue/yellow/orange/red]",
    "description": "[description text]",
    "overall_percentage": [X.XX],
    "overall_score": [0.XXXX],
    "sources": [],
    "success": true,
    "total_parts": 1,
    "total_sources_analyzed": 0,
    "total_sources_found": 0
  }},
  "plagiarism_sources": []
}}
```

ENHANCED EVALUATION GUIDELINES:
1. If reference answer available: Compare student directly against reference
2. Apply reference similarity bonus: multiply base scores by {1 + reference_similarity_bonus:.3f}
3. Award high scores (80-100%) if student content closely matches reference
4. Award perfect scores (95-100%) if similarity is 90%+ and well-written
5. Apply gibberish penalty: multiply by {1-gibberish_penalty:.3f}
6. Focus feedback on reference answer alignment when available
7. Include {len(levels)} "why" explanations for each criterion

RUBRIC CRITERIA:
{rubrics_criteria}

ESSAY TO EVALUATE:
{essay}"""

    # Continue with existing API call logic...
    payload = {
        "contents": [
            {
                "parts": [
                    {"text": initiate_prompt}
                ]
            }
        ],
        "generationConfig": {
            "temperature": 0.0,
            "topP": 0.9,
            "topK": 40,
            "maxOutputTokens": 4096
        }
    }

    try:
        response = requests.post(
            f"{URL}?key={API_KEY}",
            headers=headers,
            data=json.dumps(payload)
        )

        if response.status_code != 200:
            return jsonify({"error": f"API Error {response.status_code}: {response.text}"}), response.status_code

        response_data = response.json()
        raw_text = response_data['candidates'][0]['content']['parts'][0]['text']

        # Enhanced JSON extraction and post-processing
        try:
            cleaned_text = raw_text.strip()

            # Remove markdown code blocks if present
            if cleaned_text.startswith('```json'):
                cleaned_text = re.sub(r'^```json\s*', '', cleaned_text)
                cleaned_text = re.sub(r'\s*```$', '', cleaned_text)
            elif cleaned_text.startswith('```'):
                cleaned_text = re.sub(r'^```\s*', '', cleaned_text)
                cleaned_text = re.sub(r'\s*```$', '', cleaned_text)

            # Try to extract JSON object
            json_match = re.search(r'\{[\s\S]*\}', cleaned_text)
            if json_match:
                json_str = json_match.group(0)
                try:
                    parsed_json = json.loads(json_str)

                    # POST-PROCESSING: Apply reference answer bonus to scores
                    if has_reference_answer and reference_similarity_bonus > 0:
                        if 'criteria_scores' in parsed_json:
                            for criterion_name, criterion_data in parsed_json['criteria_scores'].items():
                                if 'score' in criterion_data:
                                    base_score = float(criterion_data['score'])
                                    # Apply bonus but cap at reasonable maximum
                                    enhanced_score = min(100, base_score * (1 + reference_similarity_bonus))
                                    parsed_json['criteria_scores'][criterion_name]['score'] = round(enhanced_score, 2)
                        
                        # Apply bonus to overall score
                        if 'overall_weighted_score' in parsed_json:
                            base_overall = float(parsed_json['overall_weighted_score'])
                            enhanced_overall = min(100, base_overall * (1 + reference_similarity_bonus))
                            parsed_json['overall_weighted_score'] = round(enhanced_overall, 2)

                    # Ensure AI detection values are properly set
                    if 'ai_detection' in parsed_json:
                        parsed_json['ai_detection']['formatted'] = ai_detection_result['formatted']
                        parsed_json['ai_detection']['ai_probability'] = ai_detection_result['ai_probability']
                        parsed_json['ai_detection']['human_probability'] = ai_detection_result['human_probability']

                    # Add reference answer analysis section
                    parsed_json['reference_answer_analysis'] = {
                        'has_reference': has_reference_answer,
                        'similarity_score': reference_similarity_score,
                        'bonus_applied': reference_similarity_bonus,
                        'reference_content': reference_answer[:200] + '...' if reference_answer and len(reference_answer) > 200 else reference_answer or 'None'
                    }

                    return jsonify({"evaluation": json.dumps(parsed_json, indent=2)})

                except json.JSONDecodeError as e:
                    print(f"JSON parsing error: {e}")
                    return jsonify({"evaluation": raw_text})
            else:
                return jsonify({"evaluation": raw_text})

        except Exception as parse_error:
            print(f"Response parsing error: {parse_error}")
            return jsonify({"evaluation": raw_text})

    except Exception as e:
        print(f"Error during evaluation: {e}")
        return jsonify({"error": str(e)}), 500


def calculate_enhanced_similarity(student_text, reference_text):
    """
    Calculate enhanced similarity between student essay and reference answer
    Uses multiple similarity metrics for more accurate scoring
    """
    if not student_text or not reference_text:
        return 0.0

    try:
        # Clean both texts
        student_clean = clean_text(student_text)
        reference_clean = clean_text(reference_text)

        # Method 1: Word overlap similarity (Jaccard)
        student_words = set(word.lower().strip() for word in student_clean.split() if word.strip())
        reference_words = set(word.lower().strip() for word in reference_clean.split() if word.strip())
        
        intersection = len(student_words.intersection(reference_words))
        union = len(student_words.union(reference_words))
        jaccard_similarity = intersection / union if union > 0 else 0

        # Method 2: Sequence similarity using difflib
        from difflib import SequenceMatcher
        sequence_similarity = SequenceMatcher(None, student_clean.lower(), reference_clean.lower()).ratio()

        # Method 3: Sentence-level similarity
        from nltk.tokenize import sent_tokenize
        student_sentences = sent_tokenize(student_clean)
        reference_sentences = sent_tokenize(reference_clean)

        sentence_similarities = []
        for s_sent in student_sentences:
            best_match = 0
            for r_sent in reference_sentences:
                similarity = SequenceMatcher(None, s_sent.lower(), r_sent.lower()).ratio()
                best_match = max(best_match, similarity)
            sentence_similarities.append(best_match)

        avg_sentence_similarity = sum(sentence_similarities) / len(sentence_similarities) if sentence_similarities else 0

        # Method 4: Key phrase matching
        def extract_key_phrases(text):
            # Extract phrases of 2-4 words that might be important concepts
            words = text.lower().split()
            phrases = []
            for i in range(len(words)):
                for length in range(2, 5):  # 2, 3, 4 word phrases
                    if i + length <= len(words):
                        phrase = ' '.join(words[i:i+length])
                        if len(phrase) > 6:  # Minimum phrase length
                            phrases.append(phrase)
            return set(phrases)

        student_phrases = extract_key_phrases(student_clean)
        reference_phrases = extract_key_phrases(reference_clean)
        
        phrase_intersection = len(student_phrases.intersection(reference_phrases))
        phrase_union = len(student_phrases.union(reference_phrases))
        phrase_similarity = phrase_intersection / phrase_union if phrase_union > 0 else 0

        # Method 5: Stemmed word similarity (for better concept matching)
        try:
            from nltk.stem import PorterStemmer
            stemmer = PorterStemmer()
            
            student_stemmed = set(stemmer.stem(word.lower()) for word in student_words)
            reference_stemmed = set(stemmer.stem(word.lower()) for word in reference_words)
            
            stemmed_intersection = len(student_stemmed.intersection(reference_stemmed))
            stemmed_union = len(student_stemmed.union(reference_stemmed))
            stemmed_similarity = stemmed_intersection / stemmed_union if stemmed_union > 0 else 0
        except:
            stemmed_similarity = jaccard_similarity  # Fallback

        # Weighted combination of all methods
        final_similarity = (
            jaccard_similarity * 0.25 +      # Word overlap
            sequence_similarity * 0.30 +     # Sequence matching
            avg_sentence_similarity * 0.20 + # Sentence similarity
            phrase_similarity * 0.15 +       # Key phrase matching
            stemmed_similarity * 0.10        # Stemmed similarity
        ) * 100

        # Apply length penalty if student answer is too short compared to reference
        student_length = len(student_words)
        reference_length = len(reference_words)
        
        if reference_length > 0:
            length_ratio = student_length / reference_length
            if length_ratio < 0.3:  # Student answer is less than 30% of reference length
                length_penalty = 0.8  # 20% penalty
            elif length_ratio < 0.5:  # Less than 50%
                length_penalty = 0.9  # 10% penalty
            else:
                length_penalty = 1.0  # No penalty
            
            final_similarity *= length_penalty

        return min(final_similarity, 100.0)  # Cap at 100%

    except Exception as e:
        print(f"Error calculating similarity: {e}")
        # Fallback to simple word overlap
        student_words = set(student_text.lower().split())
        reference_words = set(reference_text.lower().split())
        intersection = len(student_words.intersection(reference_words))
        union = len(student_words.union(reference_words))
        return (intersection / union * 100) if union > 0 else 0

def clean_text(text):
    """Clean text for better similarity comparison"""
    if not text:
        return ""
    
    # Remove extra whitespace and normalize
    text = ' '.join(text.strip().split())
    
    # Remove special characters but keep essential punctuation
    text = re.sub(r'[^\w\s\.\,\!\?\;\:\-]', ' ', text)
    
    # Normalize multiple spaces
    text = re.sub(r'\s+', ' ', text).strip()
    
    return text

@app.route('/analyze', methods=['POST'])
def analyze_text():
    try:
        data = request.get_json(force=True)
        essay = data.get('essay', '')

        if not essay.strip():
            return jsonify({'error': 'No essay provided'}), 400

        # Enhanced text analysis metrics
        def calculate_text_metrics(text):
            sentences = sent_tokenize(text)
            words = text.split()

            # Calculate various metrics
            avg_sentence_length = sum(len(s.split()) for s in sentences) / len(sentences) if sentences else 0
            sentence_length_variance = sum((len(s.split()) - avg_sentence_length) ** 2 for s in sentences) / len(sentences) if sentences else 0

            # Vocabulary diversity (Type-Token Ratio)
            unique_words = len(set(word.lower() for word in words if word.isalpha()))
            total_words = len([word for word in words if word.isalpha()])
            vocab_diversity = unique_words / total_words if total_words > 0 else 0

            # Complex punctuation patterns
            complex_punct_count = len(re.findall(r'[;:\-—(){}[\]"]', text))
            punct_density = complex_punct_count / len(text) if len(text) > 0 else 0

            # Transition word density
            transition_words = ['however', 'moreover', 'furthermore', 'consequently', 'nevertheless', 'therefore', 'additionally', 'subsequently', 'accordingly', 'conversely']
            transition_count = sum(1 for word in transition_words if word in text.lower())
            transition_density = transition_count / total_words if total_words > 0 else 0

            # Adverb density (often overused in AI text)
            adverb_pattern = r'\b\w+ly\b'
            adverb_count = len(re.findall(adverb_pattern, text, re.IGNORECASE))
            adverb_density = adverb_count / total_words if total_words > 0 else 0

            return {
                'sentence_count': len(sentences),
                'word_count': total_words,
                'avg_sentence_length': avg_sentence_length,
                'sentence_length_variance': sentence_length_variance,
                'vocab_diversity': vocab_diversity,
                'punct_density': punct_density,
                'transition_density': transition_density,
                'adverb_density': adverb_density
            }

        # Calculate metrics for the input text
        metrics = calculate_text_metrics(essay)

        # Enhanced prompt with more sophisticated analysis
        prompt = f"""You are an expert AI detection system with advanced linguistic analysis capabilities. Analyze the following text to determine if it was likely generated by an AI or written by a human.

ADVANCED ANALYSIS FRAMEWORK:

1. LINGUISTIC PATTERNS:
   - Sentence structure consistency and complexity variation
   - Vocabulary sophistication vs. naturalness
   - Transitional phrase usage patterns
   - Punctuation and formatting consistency

2. STYLISTIC INDICATORS:
   - Writing flow and rhythm variations
   - Personal voice and authenticity markers
   - Error patterns (typos, grammar inconsistencies)
   - Informal vs. overly polished language

3. CONTENT CHARACTERISTICS:
   - Depth of personal insight vs. generic statements
   - Specific examples vs. abstract concepts
   - Emotional authenticity vs. artificial sentiment
   - Knowledge demonstration patterns

4. QUANTITATIVE METRICS PROVIDED:
   - Text length: {len(essay)} characters
   - Sentence count: {metrics['sentence_count']}
   - Average sentence length: {metrics['avg_sentence_length']:.1f} words
   - Vocabulary diversity: {metrics['vocab_diversity']:.3f}
   - Transition word density: {metrics['transition_density']:.3f}
   - Adverb density: {metrics['adverb_density']:.3f}

AI-GENERATED INDICATORS TO LOOK FOR:
- Overly balanced sentence structures
- Excessive use of transitional phrases
- Unnaturally perfect grammar and punctuation
- Generic, safe language without personality
- Formulaic organization patterns
- Absence of colloquialisms or informal language
- Consistent tone throughout without natural variation
- Over-explanation of simple concepts
- Lack of genuine personal anecdotes or specific examples

HUMAN-WRITTEN INDICATORS TO LOOK FOR:
- Natural sentence length variation
- Occasional grammatical imperfections or typos
- Personal voice and unique expressions
- Informal language or colloquialisms
- Genuine emotional expressions
- Specific, detailed examples from experience
- Natural topic flow with some tangents
- Inconsistent formality levels
- Cultural or generational language markers

ANALYSIS INSTRUCTIONS:
1. Provide detailed analysis in the specified format
2. Consider the context and purpose of the writing
3. Weight recent AI model capabilities (they're getting more human-like)
4. Be decisive - avoid defaulting to uncertainty
5. Provide confidence reasoning for your assessment

Format your response as follows:

**DETAILED ANALYSIS:**

AI-Generated Indicators:
- [List specific patterns found that suggest AI generation]

Human-Written Indicators:
- [List specific patterns found that suggest human authorship]

**CONFIDENCE ASSESSMENT:**
[Explain your confidence level and reasoning]

**FINAL VERDICT:**
[One clear sentence stating your conclusion]

At the end, provide ONLY this JSON line with no additional text:
{{"ai_probability": X.XX, "human_probability": X.XX, "confidence_score": X.XX}}

TEXT TO ANALYZE:
{essay}"""

        payload = {
            "contents": [
                {
                    "parts": [
                        {"text": prompt}
                    ]
                }
            ],
                    "generationConfig": {
                        "temperature": 0.0,  # Lower temperature for more consistent analysis                "topP": 0.8,
                "topK": 40,
                "maxOutputTokens": 2048
            }
        }

        response = requests.post(
            f"{URL}?key={API_KEY}",
            headers=headers,
            data=json.dumps(payload)
        )

        if response.status_code != 200:
            return jsonify({'error': f"Error {response.status_code}: {response.text}"}), response.status_code

        response_data = response.json()
        full_response = response_data['candidates'][0]['content']['parts'][0]['text']

        # Enhanced JSON extraction with multiple patterns
        json_patterns = [
            r'\{[^{}]*"ai_probability"\s*:\s*([0-9.]+)[^{}]*"human_probability"\s*:\s*([0-9.]+)[^{}]*"confidence_score"\s*:\s*([0-9.]+)[^{}]*\}',
            r'\{\s*"ai_probability"\s*:\s*([0-9.]+)\s*,\s*"human_probability"\s*:\s*([0-9.]+)\s*,\s*"confidence_score"\s*:\s*([0-9.]+)\s*\}',
            r'\{.*?"ai_probability".*?([0-9.]+).*?"human_probability".*?([0-9.]+).*?"confidence_score".*?([0-9.]+).*?\}'
        ]

        match = None
        for pattern in json_patterns:
            match = re.search(pattern, full_response, re.DOTALL)
            if match:
                break

        if match:
            try:
                ai_probability = float(match.group(1))
                human_probability = float(match.group(2))
                confidence_score = float(match.group(3))

                # Validate probabilities sum to 1.0 (with small tolerance)
                if abs(ai_probability + human_probability - 1.0) > 0.01:
                    # Normalize if they don't sum to 1
                    total = ai_probability + human_probability
                    if total > 0:
                        ai_probability = ai_probability / total
                        human_probability = human_probability / total

                # Clean the explanation by removing the JSON part
                explanation = re.sub(r'\{[^{}]*"ai_probability"[^{}]*\}', '', full_response, flags=re.DOTALL).strip()

                # Additional text quality indicators
                quality_indicators = {
                    'readability_score': min(100, max(0, (100 - metrics['avg_sentence_length'] * 2))),  # Simpler sentences = higher readability
                    'complexity_score': min(100, metrics['vocab_diversity'] * 100),
                    'naturalness_score': min(100, max(0, (100 - abs(metrics['avg_sentence_length'] - 15) * 3))),  # 15 words is natural average
                    'text_metrics': metrics
                }

                return jsonify({
                    'explanation': explanation,
                    'ai_probability': round(ai_probability, 4),
                    'human_probability': round(human_probability, 4),
                    'confidence_score': round(confidence_score, 4),
                    'quality_indicators': quality_indicators,
                    'analysis_version': '2.0_enhanced'
                })

            except (ValueError, IndexError) as e:
                return jsonify({'error': f"Could not parse probabilities from response: {str(e)}"}), 500
        else:
            # Fallback: try to extract any JSON-like structure
            fallback_match = re.search(r'\{.*?"ai_probability".*?([0-9.]+).*?"human_probability".*?([0-9.]+).*?\}', full_response)
            if fallback_match:
                try:
                    ai_prob = float(fallback_match.group(1))
                    human_prob = float(fallback_match.group(2))

                    return jsonify({
                        'explanation': full_response,
                        'ai_probability': ai_prob,
                        'human_probability': human_prob,
                        'confidence_score': 0.7,  # Default confidence
                        'quality_indicators': {
                            'text_metrics': metrics
                        },
                        'analysis_version': '2.0_fallback'
                    })
                except (ValueError, IndexError):
                    pass

            return jsonify({'error': "Could not extract valid probability scores from AI response", 'raw_response': full_response}), 500

    except Exception as e:
        return jsonify({'error': f"Analysis failed: {str(e)}"}), 500

@app.route('/benchmark', methods=['POST'])
def benchmark_endpoint():
    """
    Generate teacher comment/benchmark by comparing student essay with reference answer
    """
    try:
        data = request.get_json(force=True)

        # Extract required data
        student_essay = data.get('student_essay', '').strip()
        reference_answer = data.get('reference_answer', '').strip()
        rubric_criteria = data.get('rubric_criteria', '')
        question = data.get('question', '')

        # Validation
        if not student_essay:
            return jsonify({'error': 'Student essay is required'}), 400

        if not reference_answer:
            return jsonify({'error': 'Reference answer is required'}), 400

        if not rubric_criteria:
            return jsonify({'error': 'Rubric criteria is required'}), 400

        # Create comprehensive benchmark prompt
        benchmark_prompt = f"""
You are an experienced teacher evaluating a student's essay. Please provide a detailed teacher comment/benchmark by comparing the student's response with the reference answer using the provided rubric criteria.

**Question:** {question}

**Reference Answer (Teacher's Expected Response):**
{reference_answer}

**Student's Essay:**
{student_essay}

**Rubric Criteria:**
{rubric_criteria}

**Instructions:**
1. Compare the student's essay against the reference answer
2. Evaluate based on the rubric criteria provided
3. Identify strengths and areas for improvement
4. Provide specific feedback on how the student's response aligns with or differs from the expected answer
5. Give constructive suggestions for improvement
6. Assign a preliminary score based on rubric alignment

Please respond in the following JSON format:
{{
    "teacher_comment": {{
        "overall_assessment": "Brief overall assessment of the student's performance",
        "comparison_with_reference": {{
            "similarities": ["List of ways the student's answer aligns with reference"],
            "differences": ["List of key differences from the reference answer"],
            "missing_elements": ["Important points from reference answer that student missed"]
        }},
        "rubric_analysis": {{
            "strengths": ["Specific strengths based on rubric criteria"],
            "weaknesses": ["Areas needing improvement based on rubric criteria"],
            "criterion_scores": {{
                "criterion_1": {{"score": 0, "feedback": "Specific feedback"}},
                "criterion_2": {{"score": 0, "feedback": "Specific feedback"}}
            }}
        }},
        "constructive_feedback": {{
            "specific_improvements": ["Actionable suggestions for improvement"],
            "study_recommendations": ["What the student should focus on studying"],
            "writing_tips": ["Specific writing improvement suggestions"]
        }},
        "benchmark_score": 0,
        "grade_justification": "Detailed explanation of why this score was assigned"
    }}
}}

Ensure your response is valid JSON and provides comprehensive, constructive feedback that helps the student improve.
"""

        # Call Gemini API
        payload = {
            "contents": [
                {
                    "parts": [
                        {"text": benchmark_prompt}
                    ]
                }
            ],
            "generationConfig": {
                "temperature": 0.0
            }
        }

        response = requests.post(
            f"{URL}?key={API_KEY}",
            headers=headers,
            data=json.dumps(payload)
        )

        if response.status_code != 200:
            return jsonify({'error': f"API Error {response.status_code}: {response.text}"}), response.status_code

        try:
            response_data = response.json()
            text_content = response_data['candidates'][0]['content']['parts'][0]['text']

            # Clean and parse JSON response
            if text_content.startswith("```json"):
                clean_json_str = text_content.replace("```json", "").replace("```", "").strip()
            else:
                clean_json_str = text_content.strip("` \n")

            teacher_comment_data = json.loads(clean_json_str)

            # Return the structured teacher comment
            return jsonify({
                'success': True,
                'teacher_comment': teacher_comment_data.get('teacher_comment', {}),
                'timestamp': time.time()
            })

        except json.JSONDecodeError as e:
            print("Failed to parse JSON response:", repr(clean_json_str))
            return jsonify({'error': 'Failed to parse API response as JSON'}), 500

        except Exception as e:
            print(f"Unexpected error in benchmark processing: {e}")
            return jsonify({'error': str(e)}), 500

    except Exception as e:
        print(f"Benchmark endpoint error: {e}")
        return jsonify({'error': str(e)}), 500

# Enhanced similarity computation functions
def clean_text(text):
    """Enhanced text cleaning and preprocessing"""
    if not text:
        return ""

    # Remove extra whitespace and normalize
    text = ' '.join(text.strip().split())

    # Remove special characters but keep punctuation for sentence structure
    text = re.sub(r'[^\w\s\.\,\!\?\;\:]', ' ', text)

    # Normalize multiple spaces
    text = re.sub(r'\s+', ' ', text).strip()

    return text

def preprocess_text_for_similarity(text):
    """Advanced text preprocessing for similarity computation"""
    try:
        # Clean text
        text = clean_text(text.lower())

        # Tokenize into words
        words = word_tokenize(text)

        # Remove stopwords and short words
        stop_words = set(stopwords.words('english'))
        words = [word for word in words if word not in stop_words and len(word) > 2]

        # Stem words for better matching
        stemmer = PorterStemmer()
        words = [stemmer.stem(word) for word in words if word.isalpha()]

        return words
    except Exception as e:
        # Fallback to simple preprocessing
        words = re.findall(r'\b[a-zA-Z]{3,}\b', text.lower())
        return [word for word in words if word not in ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by']]

def compute_cosine_similarity(text1, text2):
    """Enhanced cosine similarity with TF-IDF weighting"""
    try:
        # Preprocess texts
        words1 = preprocess_text_for_similarity(text1)
        words2 = preprocess_text_for_similarity(text2)

        if not words1 or not words2:
            return 0.0

        # Create vocabulary
        vocabulary = list(set(words1 + words2))

        if not vocabulary:
            return 0.0

        # Create TF-IDF vectors
        def create_tfidf_vector(words, vocab):
            # Term frequency
            tf = Counter(words)
            total_words = len(words)

            # Create vector
            vector = []
            for term in vocab:
                tf_score = tf[term] / total_words if total_words > 0 else 0
                # Simple IDF approximation
                idf_score = math.log(2 / (1 + (term in words)))
                tfidf_score = tf_score * idf_score
                vector.append(tfidf_score)

            return np.array(vector)

        vector1 = create_tfidf_vector(words1, vocabulary)
        vector2 = create_tfidf_vector(words2, vocabulary)

        # Calculate cosine similarity
        dot_product = np.dot(vector1, vector2)
        norm1 = np.linalg.norm(vector1)
        norm2 = np.linalg.norm(vector2)

        if norm1 == 0 or norm2 == 0:
            return 0.0

        similarity = dot_product / (norm1 * norm2)
        return round(max(0, min(1, similarity)) * 100, 2)

    except Exception as e:
        # Fallback to simple word overlap
        words1 = set(text1.lower().split())
        words2 = set(text2.lower().split())

        if not words1 or not words2:
            return 0.0

        intersection = len(words1.intersection(words2))
        union = len(words1.union(words2))

        return round((intersection / union * 100) if union > 0 else 0, 2)

def compute_jaccard_similarity(text1, text2):
    """Jaccard similarity for additional comparison"""
    try:
        words1 = set(preprocess_text_for_similarity(text1))
        words2 = set(preprocess_text_for_similarity(text2))

        if not words1 or not words2:
            return 0.0

        intersection = len(words1.intersection(words2))
        union = len(words1.union(words2))

        return round((intersection / union * 100) if union > 0 else 0, 2)
    except:
        return 0.0

def compute_semantic_similarity(text1, text2):
    """Advanced semantic similarity using sentence structure"""
    try:
        sentences1 = sent_tokenize(text1)
        sentences2 = sent_tokenize(text2)

        if not sentences1 or not sentences2:
            return 0.0

        # Compare sentence by sentence
        total_similarity = 0
        comparisons = 0

        for s1 in sentences1:
            best_match = 0
            for s2 in sentences2:
                # Use SequenceMatcher for sequence similarity
                similarity = SequenceMatcher(None, s1.lower(), s2.lower()).ratio()
                best_match = max(best_match, similarity)

            total_similarity += best_match
            comparisons += 1

        return round((total_similarity / comparisons * 100) if comparisons > 0 else 0, 2)
    except:
        return 0.0

def simple_sentence_match(student_text, reference_text, threshold=0.6):
    """Enhanced sentence matching with better algorithms"""
    try:
        student_sentences = sent_tokenize(student_text)
        reference_sentences = sent_tokenize(reference_text)

        matches = []

        for s_sent in student_sentences:
            s_words = set(preprocess_text_for_similarity(s_sent))

            if len(s_words) < 3:  # Skip very short sentences
                continue

            best_match = {
                'student_sentence': s_sent,
                'reference_sentence': '',
                'similarity': 0,
                'match_type': 'none'
            }

            for r_sent in reference_sentences:
                r_words = set(preprocess_text_for_similarity(r_sent))

                if len(r_words) < 3:
                    continue

                # Calculate multiple similarity metrics
                jaccard = len(s_words.intersection(r_words)) / len(s_words.union(r_words)) if s_words.union(r_words) else 0
                sequence_sim = SequenceMatcher(None, s_sent.lower(), r_sent.lower()).ratio()

                # Combined similarity score
                combined_similarity = (jaccard * 0.6 + sequence_sim * 0.4)

                if combined_similarity > best_match['similarity']:
                    best_match.update({
                        'reference_sentence': r_sent,
                        'similarity': round(combined_similarity * 100, 2),
                        'match_type': 'exact' if combined_similarity > 0.9 else 'similar' if combined_similarity > threshold else 'weak'
                    })

            if best_match['similarity'] > threshold * 100:
                matches.append(best_match)

        return matches
    except Exception as e:
        return []

def analyze_content_coverage(student_text, reference_text):
    """Analyze how well student covers reference content"""
    try:
        # Extract key concepts from both texts
        student_concepts = set(preprocess_text_for_similarity(student_text))
        reference_concepts = set(preprocess_text_for_similarity(reference_text))

        if not reference_concepts:
            return {'coverage': 0, 'missing_concepts': [], 'extra_concepts': []}

        # Calculate coverage
        covered_concepts = student_concepts.intersection(reference_concepts)
        missing_concepts = reference_concepts - student_concepts
        extra_concepts = student_concepts - reference_concepts

        coverage_percentage = (len(covered_concepts) / len(reference_concepts)) * 100

        return {
            'coverage': round(coverage_percentage, 2),
            'covered_concepts': list(covered_concepts)[:10],  # Limit for response size
            'missing_concepts': list(missing_concepts)[:10],
            'extra_concepts': list(extra_concepts)[:10],
            'total_reference_concepts': len(reference_concepts),
            'total_student_concepts': len(student_concepts)
        }
    except:
        return {'coverage': 0, 'missing_concepts': [], 'extra_concepts': []}

def evaluate_with_rubric(student_answer, reference_answer, rubric_criteria):
    """Enhanced rubric evaluation with multiple metrics"""
    evaluation = {}

    # Calculate various similarity metrics
    cosine_sim = compute_cosine_similarity(student_answer, reference_answer)
    jaccard_sim = compute_jaccard_similarity(student_answer, reference_answer)
    semantic_sim = compute_semantic_similarity(student_answer, reference_answer)
    content_analysis = analyze_content_coverage(student_answer, reference_answer)

    # Overall similarity (weighted average)
    overall_similarity = (cosine_sim * 0.4 + jaccard_sim * 0.3 + semantic_sim * 0.3)

    # Text quality metrics
    student_sentences = sent_tokenize(student_answer)
    reference_sentences = sent_tokenize(reference_answer)

    completeness_score = min(100, (len(student_sentences) / len(reference_sentences)) * 100) if reference_sentences else 0

    # Process rubric criteria
    if isinstance(rubric_criteria, dict):
        for criterion, details in rubric_criteria.items():
            if isinstance(details, dict):
                max_points = details.get('max_points', 10)
                weight = details.get('weight', 1.0)

                # Enhanced scoring based on multiple factors
                base_score = overall_similarity / 100

                # Adjust based on content coverage
                coverage_factor = content_analysis['coverage'] / 100
                adjusted_score = (base_score * 0.7 + coverage_factor * 0.3)

                # Apply completeness factor
                completeness_factor = min(1.0, completeness_score / 100)
                final_score = adjusted_score * completeness_factor

                points_earned = final_score * max_points

                evaluation[criterion] = {
                    'score': round(points_earned, 2),
                    'max_points': max_points,
                    'weight': weight,
                    'percentage': round(final_score * 100, 2),
                    'similarity_breakdown': {
                        'cosine': cosine_sim,
                        'jaccard': jaccard_sim,
                        'semantic': semantic_sim,
                        'overall': round(overall_similarity, 2)
                    }
                }
            else:
                # Handle simple rubric format
                evaluation[criterion] = {
                    'score': round(overall_similarity * 0.1, 2),
                    'percentage': round(overall_similarity, 2)
                }

    # Add summary metrics
    evaluation['_summary'] = {
        'overall_similarity': round(overall_similarity, 2),
        'content_coverage': content_analysis,
        'completeness_score': round(completeness_score, 2),
        'quality_indicators': {
            'student_sentence_count': len(student_sentences),
            'reference_sentence_count': len(reference_sentences),
            'length_ratio': round(len(student_answer) / len(reference_answer), 2) if reference_answer else 0
        }
    }

    return evaluation

# Add this new endpoint to your existing Flask application (paste-3.txt)

@app.route('/compare', methods=['POST'])
def compare_answer_endpoint():
    """
    Compare student answer with reference answer using rubrics
    Now analyzes each criterion individually and provides detailed per-criterion feedback
    """
    try:
        data = request.get_json(force=True)

        student_answer = data.get('student_answer', '').strip()
        reference_answer = data.get('reference_answer', '').strip()
        rubric_data = data.get('rubric_data', {})
        question = data.get('question', '').strip()

        # Validate inputs
        if not student_answer:
            return jsonify({
                'success': False,
                'error': 'Student answer is required'
            }), 400

        if not reference_answer:
            return jsonify({
                'success': False,
                'error': 'Reference answer is required'
            }), 400

        if not rubric_data:
            return jsonify({
                'success': False,
                'error': 'Rubric data is required'
            }), 400

        # Calculate overall similarity between student and reference answers
        overall_similarity_score = calculate_text_similarity(student_answer, reference_answer)

        # Extract rubric headers and rows for individual criteria analysis
        rubric_headers = rubric_data.get('headers', [])
        rubric_rows = rubric_data.get('rows', [])

        # Determine overall rubric level
        overall_matched_header = determine_rubric_level(overall_similarity_score, rubric_headers)

        # NEW: Analyze each criterion individually with AI assistance
        individual_criteria_analysis = analyze_criteria_individually(
            student_answer, reference_answer, rubric_rows, rubric_headers, question
        )

        # Create response structure with individual criterion analysis
        response_data = {
            'success': True,
            'overall_similarity_score': overall_similarity_score,
            'overall_matched_header': overall_matched_header,
            'comparison_timestamp': time.time(),

            # NEW: Individual criteria comparisons
            'criteria_analysis': individual_criteria_analysis,

            # Overall analysis (summary of all criteria)
            'overall_analysis': generate_overall_analysis(individual_criteria_analysis),

            # Detailed recommendation based on all criteria
            'detailed_recommendation': generate_detailed_recommendation(individual_criteria_analysis, overall_similarity_score)
        }

        return jsonify(response_data)

    except Exception as e:
        return jsonify({
            'success': False,
            'error': f'Comparison failed: {str(e)}'
        }), 500


def analyze_criteria_individually(student_answer, reference_answer, rubric_rows, rubric_headers, question):
    """
    Analyze each rubric criterion individually against the reference answer
    """
    criteria_analysis = {}

    for i, row in enumerate(rubric_rows):
        criteria_name = row.get('criteria', f'Criterion {i+1}')
        criteria_cells = row.get('cells', [])

        # Skip if criteria name is empty
        if not criteria_name.strip():
            continue

        print(f"Analyzing criterion: {criteria_name}")

        # Analyze this specific criterion
        criterion_analysis = analyze_single_criterion(
            student_answer,
            reference_answer,
            criteria_name,
            criteria_cells,
            rubric_headers,
            question
        )

        criteria_analysis[criteria_name] = criterion_analysis

    return criteria_analysis


def analyze_single_criterion(student_answer, reference_answer, criteria_name, criteria_cells, rubric_headers, question):
    """
    Analyze a single criterion by comparing student and reference answers specifically for that criterion
    """
    try:
        # Create a detailed prompt for this specific criterion
        criterion_prompt = f"""
        You are an expert educational evaluator. Analyze the student's answer specifically for the criterion "{criteria_name}" by comparing it with the reference answer.

        Question: {question}

        Reference Answer: {reference_answer}

        Student Answer: {student_answer}

        Rubric Criterion: {criteria_name}
        Rubric Levels: {', '.join(rubric_headers)}
        Rubric Descriptions for this criterion: {criteria_cells}

        Focus ONLY on how well the student's answer meets the "{criteria_name}" criterion compared to the reference answer.

        Provide your analysis in the following JSON format:
        {{
            "criterion_name": "{criteria_name}",
            "similarity_score": [0-100 score for this specific criterion],
            "matched_level": "[which rubric level this criterion achieves]",
            "criterion_analysis": {{
                "strengths": ["specific strength 1 for this criterion", "specific strength 2"],
                "weaknesses": ["specific weakness 1 for this criterion", "specific weakness 2"],
                "key_points_covered": ["point 1 covered for this criterion", "point 2"],
                "missing_points": ["missing point 1 for this criterion", "missing point 2"],
                "suggestions": ["specific suggestion 1 for improving this criterion", "suggestion 2"]
            }},
            "performance_level": "Detailed assessment of performance for this specific criterion",
            "criterion_comparison": "Direct comparison of how student and reference answers address this specific criterion",
            "improvement_focus": "Specific areas where student should focus to improve this criterion"
        }}

        Analyze ONLY this criterion. Do not provide overall feedback. Focus specifically on "{criteria_name}".
        Provide only the JSON response without any additional text or formatting.
        """

        # Call AI API for this specific criterion
        payload = {
            "contents": [
                {
                    "parts": [
                        {"text": criterion_prompt}
                    ]
                }
            ]
        }

        response = requests.post(
            f"{URL}?key={API_KEY}",
            headers=headers,
            data=json.dumps(payload)
        )

        if response.status_code == 200:
            try:
                response_data = response.json()
                text_content = response_data['candidates'][0]['content']['parts'][0]['text']

                # Clean the response
                if text_content.startswith("```json"):
                    clean_json_str = text_content.replace("```json", "").replace("```", "").strip()
                else:
                    clean_json_str = text_content.strip("` \n")

                # Parse the AI response
                ai_analysis = json.loads(clean_json_str)
                return ai_analysis

            except (json.JSONDecodeError, KeyError) as e:
                print(f"Error parsing AI response for criterion {criteria_name}: {e}")
                # Fall back to basic analysis
                return create_fallback_criterion_analysis(criteria_name, student_answer, reference_answer, rubric_headers)

        else:
            print(f"AI API error for criterion {criteria_name}: {response.status_code}")
            # Fall back to basic analysis
            return create_fallback_criterion_analysis(criteria_name, student_answer, reference_answer, rubric_headers)

    except Exception as e:
        print(f"Exception analyzing criterion {criteria_name}: {e}")
        return create_fallback_criterion_analysis(criteria_name, student_answer, reference_answer, rubric_headers)


def create_fallback_criterion_analysis(criteria_name, student_answer, reference_answer, rubric_headers):
    """
    Create a basic analysis when AI analysis fails
    """
    # Calculate basic similarity for this criterion
    criterion_similarity = calculate_criterion_similarity(student_answer, reference_answer, criteria_name)
    matched_level = determine_rubric_level(criterion_similarity, rubric_headers)

    return {
        "criterion_name": criteria_name,
        "similarity_score": criterion_similarity,
        "matched_level": matched_level,
        "criterion_analysis": {
            "strengths": ["Answer addresses the question"],
            "weaknesses": ["Detailed analysis unavailable"],
            "key_points_covered": ["Basic response provided"],
            "missing_points": ["Unable to determine specific missing points"],
            "suggestions": [f"Review reference answer for {criteria_name} guidance", "Seek additional feedback"]
        },
        "performance_level": f"Basic analysis for {criteria_name}. Detailed AI analysis was unavailable.",
        "criterion_comparison": f"Student answer shows {criterion_similarity:.1f}% similarity to reference for {criteria_name}",
        "improvement_focus": f"Focus on improving {criteria_name} by comparing with reference answer"
    }


def calculate_criterion_similarity(student_answer, reference_answer, criteria_name):
    """
    Calculate similarity score focusing on a specific criterion
    """
    # Extract criterion-specific keywords
    criterion_keywords = extract_criterion_keywords(criteria_name, {})

    # Get criterion-relevant content
    student_relevant = extract_relevant_content(student_answer, criterion_keywords)
    reference_relevant = extract_relevant_content(reference_answer, criterion_keywords)

    # Calculate similarity
    if student_relevant and reference_relevant:
        return calculate_text_similarity(student_relevant, reference_relevant)
    else:
        # If no specific content found, use overall similarity with penalty
        overall_sim = calculate_text_similarity(student_answer, reference_answer)
        return overall_sim * 0.8  # Apply penalty for lack of specific content


def generate_overall_analysis(individual_criteria_analysis):
    """
    Generate overall analysis based on individual criteria analyses
    """
    if not individual_criteria_analysis:
        return {
            "overall_strengths": ["Basic response provided"],
            "overall_weaknesses": ["Analysis unavailable"],
            "overall_suggestions": ["Review all criteria against reference answer"],
            "summary": "Overall analysis unavailable"
        }

    all_strengths = []
    all_weaknesses = []
    all_suggestions = []
    scores = []

    for criterion_name, analysis in individual_criteria_analysis.items():
        criterion_analysis = analysis.get('criterion_analysis', {})
        all_strengths.extend(criterion_analysis.get('strengths', []))
        all_weaknesses.extend(criterion_analysis.get('weaknesses', []))
        all_suggestions.extend(criterion_analysis.get('suggestions', []))
        scores.append(analysis.get('similarity_score', 0))

    avg_score = sum(scores) / len(scores) if scores else 0

    return {
        "overall_strengths": list(set(all_strengths))[:5],  # Remove duplicates, limit to top 5
        "overall_weaknesses": list(set(all_weaknesses))[:5],
        "overall_suggestions": list(set(all_suggestions))[:5],
        "summary": f"Average performance across all criteria: {avg_score:.1f}%. Analysis covers {len(individual_criteria_analysis)} criteria.",
        "average_score": avg_score
    }


def generate_detailed_recommendation(individual_criteria_analysis, overall_similarity_score):
    """
    Generate detailed recommendation based on individual criteria performance
    """
    if not individual_criteria_analysis:
        return f"Overall similarity: {overall_similarity_score:.1f}%. Individual criterion analysis unavailable."

    recommendations = []

    # Analyze performance by criterion
    low_performing_criteria = []
    high_performing_criteria = []

    for criterion_name, analysis in individual_criteria_analysis.items():
        score = analysis.get('similarity_score', 0)
        if score < 50:
            low_performing_criteria.append((criterion_name, score))
        elif score > 80:
            high_performing_criteria.append((criterion_name, score))

    # Build recommendation
    if high_performing_criteria:
        criteria_names = [name for name, _ in high_performing_criteria]
        recommendations.append(f"Strong performance in: {', '.join(criteria_names)}")

    if low_performing_criteria:
        criteria_names = [name for name, _ in low_performing_criteria]
        recommendations.append(f"Needs improvement in: {', '.join(criteria_names)}")

    # Add specific improvement suggestions
    all_improvement_focuses = []
    for analysis in individual_criteria_analysis.values():
        focus = analysis.get('improvement_focus', '')
        if focus:
            all_improvement_focuses.append(focus)

    if all_improvement_focuses:
        recommendations.extend(all_improvement_focuses[:3])  # Limit to top 3

    return '. '.join(recommendations) if recommendations else f"Overall similarity: {overall_similarity_score:.1f}%. Review individual criteria for specific guidance."


# Keep the existing helper functions (they're still needed)
def extract_criterion_keywords(criteria_name, rubric_row):
    """Extract keywords related to a specific criterion"""
    keywords = []

    # Add words from criteria name
    keywords.extend(criteria_name.lower().split())

    # Add words from rubric cells that describe this criterion
    cells = rubric_row.get('cells', [])
    for cell in cells:
        if cell and isinstance(cell, str):
            cell_words = cell.lower().split()
            keywords.extend(cell_words)

    # Common academic writing keywords by criterion type
    criterion_keywords = {
        'thesis': ['thesis', 'argument', 'claim', 'position', 'main', 'central', 'point'],
        'evidence': ['evidence', 'support', 'example', 'data', 'proof', 'citation', 'source'],
        'organization': ['organization', 'structure', 'paragraph', 'flow', 'transition', 'logical'],
        'grammar': ['grammar', 'syntax', 'sentence', 'punctuation', 'spelling', 'language'],
        'analysis': ['analysis', 'analyze', 'interpret', 'explain', 'evaluate', 'assess'],
        'clarity': ['clarity', 'clear', 'concise', 'coherent', 'understandable', 'readable']
    }

    # Add criterion-specific keywords based on criteria name
    criteria_lower = criteria_name.lower()
    for key, related_keywords in criterion_keywords.items():
        if key in criteria_lower:
            keywords.extend(related_keywords)

    return list(set(keywords))  # Remove duplicates


def extract_relevant_content(text, keywords):
    """Extract sentences from text that are relevant to specific keywords"""
    if not text or not keywords:
        return text

    sentences = sent_tokenize(text)
    relevant_sentences = []

    for sentence in sentences:
        sentence_lower = sentence.lower()
        # Check if sentence contains any of the criterion keywords
        if any(keyword in sentence_lower for keyword in keywords):
            relevant_sentences.append(sentence)

    # If no specific sentences found, return the full text
    if not relevant_sentences:
        return text

    return ' '.join(relevant_sentences)


def calculate_text_similarity(text1, text2):
    """Calculate similarity between two texts using multiple methods"""
    if not text1 or not text2:
        return 0.0

    # Method 1: Word overlap similarity
    words1 = set(word.lower() for word in word_tokenize(text1) if word.isalpha())
    words2 = set(word.lower() for word in word_tokenize(text2) if word.isalpha())

    intersection = len(words1.intersection(words2))
    union = len(words1.union(words2))
    jaccard_similarity = intersection / union if union > 0 else 0

    # Method 2: Sequence similarity
    sequence_similarity = SequenceMatcher(None, text1.lower(), text2.lower()).ratio()

    # Method 3: Sentence-level similarity
    sentences1 = sent_tokenize(text1)
    sentences2 = sent_tokenize(text2)

    sentence_similarities = []
    for s1 in sentences1:
        best_match = 0
        for s2 in sentences2:
            similarity = SequenceMatcher(None, s1.lower(), s2.lower()).ratio()
            best_match = max(best_match, similarity)
        sentence_similarities.append(best_match)

    avg_sentence_similarity = sum(sentence_similarities) / len(sentence_similarities) if sentence_similarities else 0

    # Method 4: Stemmed word similarity
    stemmer = PorterStemmer()
    stemmed_words1 = set(stemmer.stem(word.lower()) for word in word_tokenize(text1) if word.isalpha())
    stemmed_words2 = set(stemmer.stem(word.lower()) for word in word_tokenize(text2) if word.isalpha())

    stemmed_intersection = len(stemmed_words1.intersection(stemmed_words2))
    stemmed_union = len(stemmed_words1.union(stemmed_words2))
    stemmed_similarity = stemmed_intersection / stemmed_union if stemmed_union > 0 else 0

    # Weighted combination of all methods
    final_similarity = (
        jaccard_similarity * 0.25 +
        sequence_similarity * 0.35 +
        avg_sentence_similarity * 0.25 +
        stemmed_similarity * 0.15
    ) * 100

    return min(final_similarity, 100.0)  # Cap at 100%


def determine_rubric_level(similarity_score, rubric_headers):
    """
    Determine which rubric level the answer matches based on similarity score
    Returns only the level name (e.g., "Good", "Better", "Best")
    """
    if not rubric_headers:
        return "Unknown"

    # Remove any weight columns (typically contain % or numbers)
    filtered_headers = [h.strip() for h in rubric_headers if not any(char in h for char in ['%', 'Weight', 'weight'])]

    if not filtered_headers:
        return "Unknown"

    num_levels = len(filtered_headers)

    if num_levels == 1:
        return filtered_headers[0]

    # Calculate thresholds based on number of levels
    if num_levels == 2:
        thresholds = [50]
    elif num_levels == 3:
        thresholds = [40, 70]
    elif num_levels == 4:
        thresholds = [30, 50, 75]
    elif num_levels == 5:
        thresholds = [25, 45, 65, 85]
    else:
        # For more than 5 levels, distribute evenly
        step = 100 / num_levels
        thresholds = [step * i for i in range(1, num_levels)]

    # Determine level based on similarity score
    for i, threshold in enumerate(thresholds):
        if similarity_score <= threshold:
            return filtered_headers[i]

    # If score is above all thresholds, return the highest level
    return filtered_headers[-1]