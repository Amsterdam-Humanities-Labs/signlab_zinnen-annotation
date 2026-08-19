#!/usr/bin/env python3
import json
import requests

# Corrections identified
corrections = [
    {
        "sentenceId": "2",
        "lemmas": ["doen", "je", "schoen", "aan"]
    },
    {
        "sentenceId": "7", 
        "lemmas": ["even", "een", "schoon", "onderbroek", "aandoen"]
    },
    {
        "sentenceId": "11",
        "lemmas": ["hebben", "je", "een", "schoon", "onderbroek", "aantrekken"]
    },
    {
        "sentenceId": "12",
        "lemmas": ["het", "gaan", "regenen", "vandaag", "dus", "je", "moeten", "lang", "mouw", "aan"]
    }
]

# Update each sentence
for correction in corrections:
    url = "https://signcollect.nl/zin/updateLemmas.php"
    headers = {"Content-Type": "application/json"}
    
    response = requests.post(url, json=correction, headers=headers)
    
    print(f"Updating sentence {correction['sentenceId']}...")
    print(f"New lemmas: {correction['lemmas']}")
    print(f"Response: {response.status_code}")
    if response.text:
        print(f"Response body: {response.text}")
    print("-" * 50)