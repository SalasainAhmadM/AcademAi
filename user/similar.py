"""
Advanced Answer Similarity Analysis
Handles paraphrases, synonyms, and semantic similarity between student and benchmark answers.
"""

from difflib import SequenceMatcher
import re
from typing import Dict, Tuple
import json


class AnswerSimilarity:
    """
    Advanced similarity checker that handles paraphrases and synonyms.
    Uses multiple techniques to determine if answers match.
    """
    
    # Common synonym mappings for educational content
    SYNONYMS = {
        'planet': ['world', 'celestial body', 'sphere'],
        'earth': ['world', 'globe', 'planet earth'],
        'support': ['sustain', 'maintain', 'enable', 'allow'],
        'life': ['living organisms', 'living things', 'organisms', 'species'],
        'ecosystem': ['habitat', 'environment', 'biome'],
        'ocean': ['sea', 'marine environment', 'water body'],
        'atmosphere': ['air', 'atmospheric layer', 'sky'],
        'protect': ['shield', 'guard', 'preserve', 'safeguard'],
        'feature': ['characteristic', 'have', 'possess', 'include'],
        'diverse': ['various', 'wide range', 'varied', 'different'],
        'extensive': ['vast', 'large', 'wide', 'broad'],
        'countless': ['many', 'numerous', 'multiple'],
        'unique': ['special', 'distinctive', 'one-of-a-kind'],
        'balance': ['equilibrium', 'harmony', 'blend', 'mix'],
    }
    
    def __init__(self):
        pass
    
    def normalize_text(self, text: str) -> str:
        """Normalize text for comparison."""
        # Convert to lowercase
        text = text.lower().strip()
        
        # Remove extra whitespace
        text = re.sub(r'\s+', ' ', text)
        
        # Remove punctuation but keep sentence structure
        text = re.sub(r'[^\w\s]', ' ', text)
        
        return text
    
    def get_synonyms(self, word: str) -> list:
        """Get synonyms for a word."""
        word = word.lower()
        synonyms = [word]
        
        if word in self.SYNONYMS:
            synonyms.extend(self.SYNONYMS[word])
        
        # Check if word appears in any synonym list
        for key, values in self.SYNONYMS.items():
            if word in values:
                synonyms.append(key)
                synonyms.extend(values)
        
        return list(set(synonyms))
    
    def expand_with_synonyms(self, text: str) -> str:
        """Expand text with synonyms for better matching."""
        words = text.split()
        expanded_words = []
        
        for word in words:
            synonyms = self.get_synonyms(word)
            # Add word and its first synonym
            expanded_words.append(word)
            if len(synonyms) > 1:
                expanded_words.append(synonyms[1])
        
        return ' '.join(expanded_words)
    
    def extract_key_concepts(self, text: str) -> set:
        """Extract key concepts from text."""
        # Common stop words to ignore
        stop_words = {
            'the', 'is', 'are', 'was', 'were', 'a', 'an', 'and', 'or', 'but',
            'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'that',
            'this', 'it', 'as', 'be', 'has', 'have', 'had'
        }
        
        words = self.normalize_text(text).split()
        key_concepts = set()
        
        for word in words:
            # Keep words longer than 3 characters that aren't stop words
            if len(word) > 3 and word not in stop_words:
                key_concepts.add(word)
                # Add synonyms to key concepts
                key_concepts.update(self.get_synonyms(word))
        
        return key_concepts
    
    def calculate_sequence_similarity(self, text1: str, text2: str) -> float:
        """Calculate basic sequence similarity (0-100)."""
        normalized1 = self.normalize_text(text1)
        normalized2 = self.normalize_text(text2)
        
        return SequenceMatcher(None, normalized1, normalized2).ratio() * 100
    
    def calculate_concept_overlap(self, text1: str, text2: str) -> float:
        """Calculate similarity based on concept overlap (0-100)."""
        concepts1 = self.extract_key_concepts(text1)
        concepts2 = self.extract_key_concepts(text2)
        
        if not concepts1 or not concepts2:
            return 0.0
        
        # Calculate Jaccard similarity
        intersection = len(concepts1 & concepts2)
        union = len(concepts1 | concepts2)
        
        return (intersection / union * 100) if union > 0 else 0.0
    
    def calculate_synonym_similarity(self, text1: str, text2: str) -> float:
        """Calculate similarity with synonym expansion (0-100)."""
        expanded1 = self.expand_with_synonyms(self.normalize_text(text1))
        expanded2 = self.expand_with_synonyms(self.normalize_text(text2))
        
        return SequenceMatcher(None, expanded1, expanded2).ratio() * 100
    
    def check_key_phrases(self, text1: str, text2: str) -> Tuple[float, list]:
        """Check if key phrases from benchmark appear in student answer."""
        # Extract sentences from benchmark
        benchmark_sentences = re.split(r'[.!?]+', text2)
        benchmark_sentences = [s.strip() for s in benchmark_sentences if s.strip()]
        
        matched_phrases = []
        total_phrases = len(benchmark_sentences)
        
        if total_phrases == 0:
            return 0.0, []
        
        normalized_student = self.normalize_text(text1)
        
        for phrase in benchmark_sentences:
            normalized_phrase = self.normalize_text(phrase)
            phrase_words = set(normalized_phrase.split())
            
            # Remove stop words
            stop_words = {'the', 'is', 'are', 'was', 'were', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with'}
            phrase_words = phrase_words - stop_words
            
            # Check if most words from phrase appear in student answer
            words_found = sum(1 for word in phrase_words if word in normalized_student)
            
            if len(phrase_words) > 0 and words_found / len(phrase_words) >= 0.6:
                matched_phrases.append(phrase)
        
        phrase_match_percentage = (len(matched_phrases) / total_phrases * 100)
        return phrase_match_percentage, matched_phrases
    
    def compare_answers(self, student_answer: str, benchmark_answer: str) -> Dict:
        """
        Compare student answer with benchmark answer using multiple methods.
        
        Returns:
            Dictionary containing similarity scores and analysis
        """
        # Calculate different similarity metrics
        sequence_sim = self.calculate_sequence_similarity(student_answer, benchmark_answer)
        concept_sim = self.calculate_concept_overlap(student_answer, benchmark_answer)
        synonym_sim = self.calculate_synonym_similarity(student_answer, benchmark_answer)
        phrase_match, matched_phrases = self.check_key_phrases(student_answer, benchmark_answer)
        
        # Calculate weighted average (giving more weight to semantic similarity)
        weighted_similarity = (
            sequence_sim * 0.20 +
            concept_sim * 0.30 +
            synonym_sim * 0.30 +
            phrase_match * 0.20
        )
        
        # Determine similarity level
        if weighted_similarity >= 95:
            level = 'Accurate'
            description = 'Excellent alignment with benchmark - comprehensive understanding'
        elif weighted_similarity >= 80:
            level = 'Mostly Accurate'
            description = 'Strong alignment with benchmark - good understanding'
        elif weighted_similarity >= 60:
            level = 'Likely Accurate'
            description = 'Moderate alignment - key points covered but some details missing'
        elif weighted_similarity >= 40:
            level = 'Not Accurate'
            description = 'Limited alignment - many key points missing'
        else:
            level = 'Not Really Accurate'
            description = 'Minimal alignment - substantial revision needed'
        
        return {
            'similarity_percentage': round(weighted_similarity, 2),
            'similarity_level': level,
            'description': description,
            'metrics': {
                'sequence_similarity': round(sequence_sim, 2),
                'concept_overlap': round(concept_sim, 2),
                'synonym_similarity': round(synonym_sim, 2),
                'phrase_match': round(phrase_match, 2)
            },
            'matched_phrases': matched_phrases,
            'key_concepts_student': list(self.extract_key_concepts(student_answer)),
            'key_concepts_benchmark': list(self.extract_key_concepts(benchmark_answer))
        }


def analyze_similarity(student_answer: str, benchmark_answer: str) -> str:
    """
    Main function to analyze similarity between answers.
    Returns JSON string for easy integration with PHP.
    """
    analyzer = AnswerSimilarity()
    result = analyzer.compare_answers(student_answer, benchmark_answer)
    return json.dumps(result, indent=2)


# Example usage
if __name__ == "__main__":
    # Test with the example from your image
    student = """Earth, the third planet from the Sun, is the only known planet that 
    supports life. It features a wide range of ecosystems, extensive oceans, and 
    continents, all protected by its atmosphere. With its harmonious blend of land, water, 
    and air, Earth provides a home for countless living species, including humans."""
    
    benchmark = """The Earth is the third planet from the Sun and the only known world to 
    support life. It has diverse ecosystems, vast oceans, continents, and an atmosphere 
    that protects living organisms. With its unique balance of water, land, and air, Earth 
    sustains countless species, including humans."""
    
    print("Analyzing answer similarity...")
    print("=" * 80)
    
    analyzer = AnswerSimilarity()
    result = analyzer.compare_answers(student, benchmark)
    
    print(f"\nSimilarity Level: {result['similarity_level']}")
    print(f"Overall Score: {result['similarity_percentage']}%")
    print(f"\nDescription: {result['description']}")
    
    print("\nDetailed Metrics:")
    for metric, score in result['metrics'].items():
        print(f"  - {metric.replace('_', ' ').title()}: {score}%")
    
    print(f"\nMatched Key Phrases: {len(result['matched_phrases'])}")
    for i, phrase in enumerate(result['matched_phrases'], 1):
        print(f"  {i}. {phrase}")
    
    print("\n" + "=" * 80)
    print("\nJSON Output (for PHP integration):")
    print(analyze_similarity(student, benchmark))