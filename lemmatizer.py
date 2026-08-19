#!/usr/bin/env python3
import json
import requests
import re
from typing import List, Dict, Tuple

class DutchLemmatizer:
    def __init__(self):
        # Common verb conjugations to infinitive
        self.verb_forms = {
            # hebben
            'heb': 'hebben', 'hebt': 'hebben', 'heeft': 'hebben', 'had': 'hebben', 'hadden': 'hebben',
            # zijn
            'ben': 'zijn', 'bent': 'zijn', 'is': 'zijn', 'was': 'zijn', 'waren': 'zijn',
            # gaan
            'ga': 'gaan', 'gaat': 'gaan', 'ging': 'gaan', 'gingen': 'gaan', 'gegaan': 'gaan',
            # doen
            'doe': 'doen', 'doet': 'doen', 'deed': 'doen', 'deden': 'doen', 'gedaan': 'doen',
            # moeten
            'moet': 'moeten', 'moest': 'moeten', 'moesten': 'moeten',
            # kunnen
            'kan': 'kunnen', 'kunt': 'kunnen', 'kon': 'kunnen', 'konden': 'kunnen',
            # willen
            'wil': 'willen', 'wilt': 'willen', 'wilde': 'willen', 'wilden': 'willen',
            # komen
            'kom': 'komen', 'komt': 'komen', 'kwam': 'komen', 'kwamen': 'komen', 'gekomen': 'komen',
            # zien
            'zie': 'zien', 'ziet': 'zien', 'zag': 'zien', 'zagen': 'zien', 'gezien': 'zien',
            # maken
            'maak': 'maken', 'maakt': 'maken', 'maakte': 'maken', 'maakten': 'maken', 'gemaakt': 'maken',
        }
        
        # Past participles to infinitive
        self.participles = {
            'aangetrokken': 'aantrekken', 'aangekleed': 'aankleden', 'aangedaan': 'aandoen',
            'gedaan': 'doen', 'gezien': 'zien', 'geweest': 'zijn', 'gegaan': 'gaan',
            'gekomen': 'komen', 'gemaakt': 'maken', 'gevraagd': 'vragen', 'gebracht': 'brengen',
            'gedacht': 'denken', 'gekregen': 'krijgen', 'gegeven': 'geven', 'genomen': 'nemen',
            'gehoord': 'horen', 'gezegd': 'zeggen', 'geworden': 'worden', 'gevonden': 'vinden',
            'begonnen': 'beginnen', 'gebleven': 'blijven', 'gelezen': 'lezen', 'geschreven': 'schrijven',
        }
        
        # Common plural patterns
        self.plural_patterns = [
            (r'en$', ''),  # honden -> hond
            (r'sen$', 's'),  # huizen -> huis (s->z change)
            (r'zen$', 's'),  # huizen -> huis
            (r'ven$', 'f'),  # brieven -> brief
        ]
        
        # Diminutive patterns
        self.diminutive_patterns = [
            (r'je$', ''),  # huisje -> huis
            (r'tje$', ''),  # boekje -> boek
            (r'etje$', ''),  # balletje -> bal
            (r'pje$', ''),  # boompje -> boom
            (r'kje$', ''),  # koninkje -> koning
        ]
        
        # Adjective inflections
        self.adjective_inflections = {
            'schone': 'schoon', 'lange': 'lang', 'grote': 'groot', 'kleine': 'klein',
            'nieuwe': 'nieuw', 'oude': 'oud', 'goede': 'goed', 'mooie': 'mooi',
            'leuke': 'leuk', 'eerste': 'eerste', 'laatste': 'laatste', 'hele': 'heel',
            'verschillende': 'verschillend', 'belangrijke': 'belangrijk',
        }

    def lemmatize_word(self, word: str) -> str:
        """Lemmatize a single Dutch word"""
        word_lower = word.lower()
        
        # Check verb forms
        if word_lower in self.verb_forms:
            return self.verb_forms[word_lower]
        
        # Check past participles
        if word_lower in self.participles:
            return self.participles[word_lower]
        
        # Check adjective inflections
        if word_lower in self.adjective_inflections:
            return self.adjective_inflections[word_lower]
        
        # Check for diminutives
        for pattern, replacement in self.diminutive_patterns:
            if re.search(pattern, word_lower):
                base = re.sub(pattern, replacement, word_lower)
                if len(base) > 2:  # Ensure we have a reasonable base
                    return base
        
        # Check for common plurals (but keep irregular plurals like 'kinderen')
        if word_lower == 'kinderen':
            return 'kind'
        elif word_lower == 'armen':
            return 'arm'
        elif word_lower == 'mouwen':
            return 'mouw'
        elif word_lower == 'schoenen':
            return 'schoen'
        elif word_lower == 'ogen':
            return 'oog'
        elif word_lower.endswith('en') and len(word_lower) > 3:
            # Check if it could be a plural
            singular = word_lower[:-2]
            if len(singular) > 2:
                return singular
        
        # Return the original word if no rule applies
        return word_lower

    def lemmatize_sentence(self, sentence: str, current_lemmas: List[str]) -> Tuple[List[str], bool]:
        """
        Lemmatize a Dutch sentence and compare with current lemmas
        Returns: (new_lemmas, needs_update)
        """
        # Tokenize sentence (simple split, could be improved)
        words = sentence.split()
        
        # Remove punctuation from words
        clean_words = []
        for word in words:
            clean_word = re.sub(r'[.,!?;:]$', '', word)
            clean_words.append(clean_word)
        
        # Lemmatize each word
        new_lemmas = []
        for word in clean_words:
            lemma = self.lemmatize_word(word)
            new_lemmas.append(lemma)
        
        # Check if update is needed
        needs_update = False
        if len(new_lemmas) == len(current_lemmas):
            for i, (new, current) in enumerate(zip(new_lemmas, current_lemmas)):
                if new != current:
                    needs_update = True
                    break
        else:
            needs_update = True
        
        return new_lemmas, needs_update

def process_page(page_num: int, lemmatizer: DutchLemmatizer) -> List[Dict]:
    """Process a single page of sentences"""
    url = f"https://signcollect.nl/zin/getLemmas.php?page={page_num}"
    
    try:
        response = requests.get(url)
        data = response.json()
        
        if not data['success']:
            print(f"Error fetching page {page_num}")
            return []
        
        updates = []
        
        for sentence_data in data['data']:
            sentence_id = sentence_data['id']
            sentence_text = sentence_data['zinString']
            current_lemmas = sentence_data['lemmaList']
            
            # Lemmatize the sentence
            new_lemmas, needs_update = lemmatizer.lemmatize_sentence(sentence_text, current_lemmas)
            
            if needs_update:
                print(f"\nSentence {sentence_id}: {sentence_text}")
                print(f"Current: {current_lemmas}")
                print(f"Updated: {new_lemmas}")
                
                updates.append({
                    'sentenceId': sentence_id,
                    'lemmas': new_lemmas
                })
        
        return updates
        
    except Exception as e:
        print(f"Error processing page {page_num}: {e}")
        return []

def update_lemmas(updates: List[Dict]):
    """Send lemma updates to the API"""
    url = "https://signcollect.nl/zin/updateLemmas.php"
    
    for update in updates:
        try:
            response = requests.post(url, json=update)
            result = response.json()
            
            if result.get('success'):
                print(f"Successfully updated sentence {update['sentenceId']}")
            else:
                print(f"Failed to update sentence {update['sentenceId']}: {result.get('message', 'Unknown error')}")
                
        except Exception as e:
            print(f"Error updating sentence {update['sentenceId']}: {e}")

def main():
    lemmatizer = DutchLemmatizer()
    
    # Get the first page to check pagination
    initial_response = requests.get("https://signcollect.nl/zin/getLemmas.php?page=1")
    initial_data = initial_response.json()
    
    total_pages = initial_data['pagination']['total_pages']
    print(f"Total pages to process: {total_pages}")
    
    # Process all pages
    for page in range(1, total_pages + 1):
        print(f"\nProcessing page {page}/{total_pages}")
        updates = process_page(page, lemmatizer)
        
        if updates:
            print(f"Found {len(updates)} sentences needing updates")
            update_lemmas(updates)
        else:
            print("No updates needed for this page")
        
        # Small delay to be nice to the server
        if page < total_pages:
            import time
            time.sleep(0.5)

if __name__ == "__main__":
    main()