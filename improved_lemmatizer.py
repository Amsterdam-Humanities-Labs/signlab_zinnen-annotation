#!/usr/bin/env python3
import json
import requests
import re
from typing import List, Dict, Tuple

class ImprovedDutchLemmatizer:
    def __init__(self):
        # Extended verb conjugations
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
            # mogen
            'mag': 'mogen', 'mocht': 'mogen', 'mochten': 'mogen',
            # zullen
            'zal': 'zullen', 'zou': 'zullen', 'zouden': 'zullen',
            # hoeven
            'hoef': 'hoeven', 'hoeft': 'hoeven',
            # krijgen
            'krijg': 'krijgen', 'krijgt': 'krijgen', 'kreeg': 'krijgen', 'kregen': 'krijgen',
            # komen
            'kom': 'komen', 'komt': 'komen', 'kwam': 'komen', 'kwamen': 'komen', 'gekomen': 'komen',
            # zien
            'zie': 'zien', 'ziet': 'zien', 'zag': 'zien', 'zagen': 'zien', 'gezien': 'zien',
            # worden
            'word': 'worden', 'wordt': 'worden', 'werd': 'worden', 'werden': 'worden',
            # maken
            'maak': 'maken', 'maakt': 'maken', 'maakte': 'maken', 'maakten': 'maken', 'gemaakt': 'maken',
            # nemen
            'neem': 'nemen', 'neemt': 'nemen', 'nam': 'nemen', 'namen': 'nemen', 'genomen': 'nemen',
            # draaien
            'draai': 'draaien', 'draait': 'draaien', 'draaide': 'draaien',
            # lezen
            'lees': 'lezen', 'leest': 'lezen', 'las': 'lezen', 'lazen': 'lezen', 'gelezen': 'lezen',
            # blijven
            'blijf': 'blijven', 'blijft': 'blijven', 'bleef': 'blijven', 'bleven': 'blijven',
            # zetten
            'zet': 'zetten', 'zette': 'zetten', 'zetten': 'zetten',
            # helpen
            'help': 'helpen', 'helpt': 'helpen', 'hielp': 'helpen', 'hielpen': 'helpen',
            # vragen
            'vraag': 'vragen', 'vraagt': 'vragen', 'vroeg': 'vragen', 'vroegen': 'vragen',
        }
        
        # Adjective inflections
        self.adjective_inflections = {
            'schone': 'schoon', 'lange': 'lang', 'grote': 'groot', 'kleine': 'klein',
            'nieuwe': 'nieuw', 'oude': 'oud', 'goede': 'goed', 'mooie': 'mooi',
            'leuke': 'leuk', 'eerste': 'eerste', 'laatste': 'laatste', 'hele': 'heel',
            'verschillende': 'verschillend', 'belangrijke': 'belangrijk',
            'warme': 'warm', 'koude': 'koud', 'dichte': 'dicht', 'lekke': 'lekker',
        }
        
        # Common plural forms
        self.plural_forms = {
            'schoenen': 'schoen', 'boeken': 'boek', 'kinderen': 'kind', 'armen': 'arm',
            'mouwen': 'mouw', 'broeken': 'broek', 'sokken': 'sok', 'blokken': 'blok',
            'kleertjes': 'kleertje', 'boekjes': 'boekje', 'auto\'s': 'auto',
        }

    def lemmatize_word(self, word: str) -> str:
        """Lemmatize a single Dutch word"""
        # Preserve contractions like "auto's"
        if '\'' in word:
            return word.lower()
            
        word_lower = word.lower()
        
        # Check verb forms first
        if word_lower in self.verb_forms:
            return self.verb_forms[word_lower]
        
        # Check adjective inflections
        if word_lower in self.adjective_inflections:
            return self.adjective_inflections[word_lower]
        
        # Check explicit plural forms
        if word_lower in self.plural_forms:
            return self.plural_forms[word_lower]
        
        # Remove common verb endings but be more careful
        if word_lower.endswith('en') and len(word_lower) > 4:
            # Only if it's likely a verb infinitive that was truncated
            base = word_lower[:-2]
            # Check if the base makes sense (not too short)
            if len(base) >= 3:
                # Common infinitive patterns
                if any(base.endswith(pattern) for pattern in ['leg', 'pak', 'zet', 'loop', 'spring', 'ren']):
                    return base + 'en'
        
        # For words ending in 'en', check if they should stay plural or become singular
        if word_lower.endswith('en') and len(word_lower) > 3:
            singular = word_lower[:-2]
            # Only make singular if it's a clear noun plural pattern
            if len(singular) >= 3 and not word_lower in ['maken', 'nemen', 'komen', 'geven', 'leven']:
                return singular
        
        return word_lower

    def process_sentence_batch(self, start_page: int, end_page: int) -> List[Dict]:
        """Process a batch of pages"""
        all_updates = []
        
        for page in range(start_page, end_page + 1):
            url = f"https://signcollect.nl/zin/getLemmas.php?page={page}"
            
            try:
                response = requests.get(url)
                data = response.json()
                
                if not data['success']:
                    print(f"Error fetching page {page}")
                    continue
                
                for sentence_data in data['data']:
                    sentence_id = sentence_data['id']
                    sentence_text = sentence_data['zinString']
                    current_lemmas = sentence_data['lemmaList']
                    
                    # Tokenize and clean sentence
                    words = sentence_text.split()
                    clean_words = [re.sub(r'[.,!?;:]$', '', word) for word in words]
                    
                    # Lemmatize each word
                    new_lemmas = [self.lemmatize_word(word) for word in clean_words]
                    
                    # Check if update is needed
                    if new_lemmas != current_lemmas:
                        all_updates.append({
                            'sentenceId': sentence_id,
                            'lemmas': new_lemmas,
                            'original': sentence_text,
                            'old_lemmas': current_lemmas
                        })
                        
            except Exception as e:
                print(f"Error processing page {page}: {e}")
                
        return all_updates

def main():
    lemmatizer = ImprovedDutchLemmatizer()
    
    # Process in smaller batches
    batch_size = 10
    total_pages = 421
    
    print(f"Processing {total_pages} pages in batches of {batch_size}")
    
    for start_page in range(1, total_pages + 1, batch_size):
        end_page = min(start_page + batch_size - 1, total_pages)
        
        print(f"\nProcessing pages {start_page}-{end_page}")
        updates = lemmatizer.process_sentence_batch(start_page, end_page)
        
        if updates:
            print(f"Found {len(updates)} sentences needing updates")
            
            # Show first few examples
            for i, update in enumerate(updates[:3]):
                print(f"  Example {i+1}: {update['original']}")
                print(f"    Old: {update['old_lemmas']}")
                print(f"    New: {update['lemmas']}")
            
            # Update the API
            for update in updates:
                try:
                    response = requests.post("https://signcollect.nl/zin/updateLemmas.php", 
                                           json={'sentenceId': update['sentenceId'], 'lemmas': update['lemmas']})
                    result = response.json()
                    
                    if not result.get('success'):
                        print(f"Failed to update sentence {update['sentenceId']}: {result.get('message', 'Unknown error')}")
                        
                except Exception as e:
                    print(f"Error updating sentence {update['sentenceId']}: {e}")
        else:
            print("No updates needed for this batch")

if __name__ == "__main__":
    main()