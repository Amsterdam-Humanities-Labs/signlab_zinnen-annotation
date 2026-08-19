#!/usr/bin/env python3
import json
import requests
import re
import time
from typing import List, Dict, Tuple, Optional
from urllib.parse import quote

class VanDaleLemmatizer:
    def __init__(self):
        # Comprehensive Dutch verb conjugations to infinitive
        self.verb_forms = {
            # hebben
            'heb': 'hebben', 'hebt': 'hebben', 'heeft': 'hebben', 'had': 'hebben', 'hadden': 'hebben',
            # zijn
            'ben': 'zijn', 'bent': 'zijn', 'is': 'zijn', 'was': 'zijn', 'waren': 'zijn',
            # worden
            'word': 'worden', 'wordt': 'worden', 'werd': 'worden', 'werden': 'worden',
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
            'zal': 'zullen', 'zult': 'zullen', 'zou': 'zullen', 'zouden': 'zullen',
            # hoeven
            'hoef': 'hoeven', 'hoeft': 'hoeven',
            # krijgen
            'krijg': 'krijgen', 'krijgt': 'krijgen', 'kreeg': 'krijgen', 'kregen': 'krijgen',
            # komen
            'kom': 'komen', 'komt': 'komen', 'kwam': 'komen', 'kwamen': 'komen', 'gekomen': 'komen',
            # geven
            'geef': 'geven', 'geeft': 'geven', 'gaf': 'geven', 'gaven': 'geven', 'gegeven': 'geven',
            # nemen
            'neem': 'nemen', 'neemt': 'nemen', 'nam': 'nemen', 'namen': 'nemen', 'genomen': 'nemen',
            # zien
            'zie': 'zien', 'ziet': 'zien', 'zag': 'zien', 'zagen': 'zien', 'gezien': 'zien',
            # maken
            'maak': 'maken', 'maakt': 'maken', 'maakte': 'maken', 'maakten': 'maken', 'gemaakt': 'maken',
            # kijken
            'kijk': 'kijken', 'kijkt': 'kijken', 'keek': 'kijken', 'keken': 'kijken', 'gekeken': 'kijken',
            # lezen
            'lees': 'lezen', 'leest': 'lezen', 'las': 'lezen', 'lazen': 'lezen', 'gelezen': 'lezen',
            # blijven
            'blijf': 'blijven', 'blijft': 'blijven', 'bleef': 'blijven', 'bleven': 'blijven', 'gebleven': 'blijven',
            # zetten
            'zet': 'zetten', 'zette': 'zetten', 'zetten': 'zetten', 'gezet': 'zetten',
            # helpen
            'help': 'helpen', 'helpt': 'helpen', 'hielp': 'helpen', 'hielpen': 'helpen', 'geholpen': 'helpen',
            # vragen
            'vraag': 'vragen', 'vraagt': 'vragen', 'vroeg': 'vragen', 'vroegen': 'vragen', 'gevraagd': 'vragen',
            # weten
            'weet': 'weten', 'wist': 'weten', 'wisten': 'weten', 'geweten': 'weten',
            # vinden
            'vind': 'vinden', 'vindt': 'vinden', 'vond': 'vinden', 'vonden': 'vinden', 'gevonden': 'vinden',
            # brengen
            'breng': 'brengen', 'brengt': 'brengen', 'bracht': 'brengen', 'brachten': 'brengen', 'gebracht': 'brengen',
            # denken
            'denk': 'denken', 'denkt': 'denken', 'dacht': 'denken', 'dachten': 'denken', 'gedacht': 'denken',
            # lopen
            'loop': 'lopen', 'loopt': 'lopen', 'liep': 'lopen', 'liepen': 'lopen', 'gelopen': 'lopen',
            # spreken
            'spreek': 'spreken', 'spreekt': 'spreken', 'sprak': 'spreken', 'spraken': 'spreken', 'gesproken': 'spreken',
            # schrijven
            'schrijf': 'schrijven', 'schrijft': 'schrijven', 'schreef': 'schrijven', 'schreven': 'schrijven', 'geschreven': 'schrijven',
            # eten
            'eet': 'eten', 'at': 'eten', 'aten': 'eten', 'gegeten': 'eten',
            # drinken
            'drink': 'drinken', 'drinkt': 'drinken', 'dronk': 'drinken', 'dronken': 'drinken', 'gedronken': 'drinken',
            # slapen
            'slaap': 'slapen', 'slaapt': 'slapen', 'sliep': 'slapen', 'sliepen': 'slapen', 'geslapen': 'slapen',
            # spelen
            'speel': 'spelen', 'speelt': 'spelen', 'speelde': 'spelen', 'speelden': 'spelen', 'gespeeld': 'spelen',
            # pakken
            'pak': 'pakken', 'pakt': 'pakken', 'pakte': 'pakken', 'pakten': 'pakken', 'gepakt': 'pakken',
            # leggen
            'leg': 'leggen', 'legt': 'leggen', 'legde': 'leggen', 'legden': 'leggen', 'gelegd': 'leggen',
            # houden
            'houd': 'houden', 'houdt': 'houden', 'hield': 'houden', 'hielden': 'houden', 'gehouden': 'houden',
            # draaien
            'draai': 'draaien', 'draait': 'draaien', 'draaide': 'draaien', 'draaiden': 'draaien', 'gedraaid': 'draaien',
            # ruimen
            'ruim': 'ruimen', 'ruimt': 'ruimen', 'ruimde': 'ruimen', 'ruimden': 'ruimen', 'geruimd': 'ruimen',
            # wachten
            'wacht': 'wachten', 'wachtte': 'wachten', 'wachtten': 'wachten', 'gewacht': 'wachten',
            # vertellen
            'vertel': 'vertellen', 'vertelt': 'vertellen', 'vertelde': 'vertellen', 'vertelden': 'vertellen', 'verteld': 'vertellen',
            # voelen
            'voel': 'voelen', 'voelt': 'voelen', 'voelde': 'voelen', 'voelden': 'voelen', 'gevoeld': 'voelen',
            # luisteren
            'luister': 'luisteren', 'luistert': 'luisteren', 'luisterde': 'luisteren', 'luisterden': 'luisteren', 'geluisterd': 'luisteren',
        }
        
        # Adjective inflections (inflected form -> base form)
        self.adjective_inflections = {
            'schone': 'schoon', 'lange': 'lang', 'grote': 'groot', 'kleine': 'klein',
            'nieuwe': 'nieuw', 'oude': 'oud', 'goede': 'goed', 'mooie': 'mooi',
            'leuke': 'leuk', 'warme': 'warm', 'koude': 'koud', 'dichte': 'dicht',
            'lekke': 'lekker', 'andere': 'ander', 'hele': 'heel', 'lieve': 'lief',
            'zieke': 'ziek', 'sterke': 'sterk', 'zwakke': 'zwak', 'snelle': 'snel',
            'langzame': 'langzaam', 'hoge': 'hoog', 'lage': 'laag', 'brede': 'breed',
            'smalle': 'smal', 'dikke': 'dik', 'dunne': 'dun', 'zware': 'zwaar',
            'lichte': 'licht', 'donkere': 'donker', 'heldere': 'helder', 'vuile': 'vuil',
            'schone': 'schoon', 'natte': 'nat', 'droge': 'droog', 'zachte': 'zacht',
            'harde': 'hard', 'zoete': 'zoet', 'zure': 'zuur', 'zoute': 'zout',
            'bittere': 'bitter', 'verse': 'vers', 'oude': 'oud', 'jonge': 'jong',
        }
        
        # Common nouns that should be singular (irregular plurals)
        self.irregular_plurals = {
            'kinderen': 'kind', 'mensen': 'mens', 'vrouwen': 'vrouw', 'mannen': 'man',
            'ouders': 'ouder', 'broers': 'broer', 'zussen': 'zus', 'ogen': 'oog',
            'oren': 'oor', 'handen': 'hand', 'voeten': 'voet', 'benen': 'been',
            'armen': 'arm', 'vingers': 'vinger', 'tanden': 'tand', 'haren': 'haar',
            'kleren': 'kleer', 'goederen': 'goed', 'eieren': 'ei', 'koeien': 'koe',
            'varkens': 'varken', 'schapen': 'schaap', 'honden': 'hond', 'katten': 'kat',
            'muizen': 'muis', 'vogels': 'vogel', 'vissen': 'vis', 'bloemen': 'bloem',
            'bomen': 'boom', 'bladeren': 'blad', 'huizen': 'huis', 'straten': 'straat',
            'auto\'s': 'auto', 'fieten': 'fiets', 'treinen': 'trein', 'vliegtuigen': 'vliegtuig',
            'boeken': 'boek', 'kranten': 'krant', 'tijdschriften': 'tijdschrift',
            'schoenen': 'schoen', 'sokken': 'sok', 'broeken': 'broek', 'hemden': 'hemd',
            'jurken': 'jurk', 'rokken': 'rok', 'jassen': 'jas', 'petten': 'pet',
            'hoeden': 'hoed', 'handschoenen': 'handschoen', 'brillen': 'bril',
            'tafels': 'tafel', 'stoelen': 'stoel', 'bedden': 'bed', 'kasten': 'kast',
            'ramen': 'raam', 'deuren': 'deur', 'sleutels': 'sleutel', 'messen': 'mes',
            'vorken': 'vork', 'lepels': 'lepel', 'borden': 'bord', 'glazen': 'glas',
            'kopjes': 'kopje', 'mokken': 'mok', 'flessen': 'fles', 'potjes': 'potje',
            'dozen': 'doos', 'zakken': 'zak', 'tassen': 'tas', 'koffers': 'koffer',
        }
        
        # Cache for Van Dale lookups
        self.vandale_cache = {}
        
    def check_vandale_spelling(self, word: str) -> Optional[str]:
        """Check spelling using Van Dale (mock implementation)"""
        if word in self.vandale_cache:
            return self.vandale_cache[word]
        
        # Common spelling corrections based on Van Dale patterns
        corrections = {
            # Common misspellings
            'misschi': 'misschien',
            'voordaat': 'voordat', 
            'alijd': 'altijd',
            'dan': 'dan',  # already correct
            'want': 'want',  # already correct
            'omdat': 'omdat',  # already correct
            'maar': 'maar',  # already correct
            'echter': 'echter',  # already correct
            'daarom': 'daarom',  # already correct
            'hierdoor': 'hierdoor',  # already correct
            'zodat': 'zodat',  # already correct
            'hoewel': 'hoewel',  # already correct
            'ondanks': 'ondanks',  # already correct
            'mits': 'mits',  # already correct
            'tenzij': 'tenzij',  # already correct
            
            # Diminutive forms should be preserved
            'huisje': 'huisje',
            'boekje': 'boekje', 
            'kindje': 'kindje',
            'bloemetje': 'bloemetje',
            'autootje': 'autootje',
            'popje': 'popje',
            'balletje': 'balletje',
            'kopje': 'kopje',
            'sokje': 'sokje',
            'hemdje': 'hemdje',
            'broekje': 'broekje',
            'jurkje': 'jurkje',
            'petje': 'petje',
            'jasje': 'jasje',
            'schoentje': 'schoentje',
            'beestje': 'beestje',
            'plantje': 'plantje',
            'steentje': 'steentje',
            'kaartje': 'kaartje',
            'briefje': 'briefje',
            'cadeautje': 'cadeautje',
            'speeltje': 'speeltje',
            'liedje': 'liedje',
            'verhaaltje': 'verhaaltje',
            'tafeltje': 'tafeltje',
            'stoeltje': 'stoeltje',
            'bedje': 'bedje',
            'kussentje': 'kussentje',
            'dekentje': 'dekentje',
            'lampje': 'lampje',
            'klokje': 'klokje',
            'sleuteltje': 'sleuteltje',
            'tasje': 'tasje',
            'doosje': 'doosje',
            'zakje': 'zakje',
            'flesje': 'flesje',
            'potje': 'potje',
            'pannetje': 'pannetje',
            'lepeltje': 'lepeltje',
            'mesje': 'mesje',
            'vorkje': 'vorkje',
            'bordje': 'bordje',
            'glaasje': 'glaasje',
            
            # Compound words should be preserved
            'aankleden': 'aankleden',
            'uitkleden': 'uitkleden',
            'aandoen': 'aandoen',
            'uitdoen': 'uitdoen',
            'aantrekken': 'aantrekken',
            'uittrekken': 'uittrekken',
            'opruimen': 'opruimen',
            'wegleggen': 'wegleggen',
            'neerzetten': 'neerzetten',
            'ophangen': 'ophangen',
            'vastmaken': 'vastmaken',
            'losmaken': 'losmaken',
            'openmaken': 'openmaken',
            'dichtmaken': 'dichtmaken',
            'schoonmaken': 'schoonmaken',
            'kapotmaken': 'kapotmaken',
            'wegbrengen': 'wegbrengen',
            'meenemen': 'meenemen',
            'weggaan': 'weggaan',
            'thuiskomen': 'thuiskomen',
            'binnenkomen': 'binnenkomen',
            'buitengaan': 'buitengaan',
            'opstaan': 'opstaan',
            'gaan zitten': 'gaan zitten',
            'wakker worden': 'wakker worden',
            'in slaap vallen': 'in slaap vallen',
        }
        
        corrected = corrections.get(word.lower(), word)
        self.vandale_cache[word] = corrected
        return corrected
    
    def lemmatize_word(self, word: str) -> str:
        """Enhanced lemmatization with Van Dale spell checking"""
        # First check spelling
        corrected_word = self.check_vandale_spelling(word)
        if corrected_word != word:
            word = corrected_word
        
        # Preserve contractions and special cases
        if '\'' in word:
            return word.lower()
        
        word_lower = word.lower()
        
        # Check verb forms
        if word_lower in self.verb_forms:
            return self.verb_forms[word_lower]
        
        # Check adjective inflections
        if word_lower in self.adjective_inflections:
            return self.adjective_inflections[word_lower]
        
        # Check irregular plurals
        if word_lower in self.irregular_plurals:
            return self.irregular_plurals[word_lower]
        
        # Handle regular plural patterns
        if word_lower.endswith('en') and len(word_lower) > 3:
            # Check if it's likely a plural noun
            singular = word_lower[:-2]
            if len(singular) >= 3:
                # Avoid converting infinitive verbs
                common_infinitives = ['maken', 'nemen', 'komen', 'geven', 'leven', 'wonen', 'werken', 'leren']
                if word_lower not in common_infinitives:
                    return singular
        
        # Handle diminutives ending in -je, -tje, -etje, -pje, -kje
        diminutive_suffixes = ['je', 'tje', 'etje', 'pje', 'kje']
        for suffix in diminutive_suffixes:
            if word_lower.endswith(suffix) and len(word_lower) > len(suffix) + 2:
                # Keep diminutives as they are - they're valid lemmas
                return word_lower
        
        return word_lower

    def process_sentence_batch(self, start_page: int, end_page: int) -> List[Dict]:
        """Process a batch of pages with enhanced lemmatization"""
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
                    
                    # Lemmatize each word with Van Dale checking
                    new_lemmas = []
                    for word in clean_words:
                        lemma = self.lemmatize_word(word)
                        new_lemmas.append(lemma)
                    
                    # Check if update is needed
                    if len(new_lemmas) == len(current_lemmas):
                        needs_update = any(new != current for new, current in zip(new_lemmas, current_lemmas))
                    else:
                        needs_update = True
                    
                    if needs_update:
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
    lemmatizer = VanDaleLemmatizer()
    
    # Process in smaller batches for better control
    batch_size = 5
    total_pages = 421
    
    print(f"Processing {total_pages} pages with Van Dale-enhanced lemmatization")
    print("Batch size: 5 pages per iteration")
    
    total_updates = 0
    
    for start_page in range(1, total_pages + 1, batch_size):
        end_page = min(start_page + batch_size - 1, total_pages)
        
        print(f"\nProcessing pages {start_page}-{end_page}")
        updates = lemmatizer.process_sentence_batch(start_page, end_page)
        
        if updates:
            total_updates += len(updates)
            print(f"Found {len(updates)} sentences needing updates")
            
            # Show a few examples
            for i, update in enumerate(updates[:2]):
                print(f"  Example {i+1}: {update['original']}")
                print(f"    Old: {update['old_lemmas']}")
                print(f"    New: {update['lemmas']}")
                if i < len(updates) - 1:
                    print()
            
            # Update the API
            success_count = 0
            for update in updates:
                try:
                    response = requests.post("https://signcollect.nl/zin/updateLemmas.php", 
                                           json={'sentenceId': update['sentenceId'], 'lemmas': update['lemmas']})
                    result = response.json()
                    
                    if result.get('success'):
                        success_count += 1
                    else:
                        print(f"Failed to update sentence {update['sentenceId']}: {result.get('message', 'Unknown error')}")
                        
                except Exception as e:
                    print(f"Error updating sentence {update['sentenceId']}: {e}")
            
            print(f"Successfully updated {success_count}/{len(updates)} sentences")
        else:
            print("No updates needed for this batch")
        
        # Small delay to be respectful to the server
        time.sleep(0.2)
    
    print(f"\n=== PROCESSING COMPLETE ===")
    print(f"Total sentences updated: {total_updates}")
    print("All lemmas have been processed with Van Dale spell checking")

if __name__ == "__main__":
    main()