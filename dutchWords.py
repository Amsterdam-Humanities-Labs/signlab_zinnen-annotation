from flask import Flask, jsonify, request
import json

app = Flask(__name__)

# Load the JSONL file into memory once
def load_jsonl_file(file_path):
    data = []
    with open(file_path, 'r', encoding='utf-8') as file:
        for line in file:
            data.append(json.loads(line.strip()))
    return data

# Initialize the data
data = load_jsonl_file('/web/zin/dutch_words.jsonl')  # Update with your actual file path

@app.route('/search', methods=['GET'])
def search():
    search_term = request.args.get('term', '').lower()
    results = [entry for entry in data if entry.get('word', '').lower() == search_term]

    if results:
        return jsonify(results)
    else:
        return jsonify({"message": f"No results found for '{search_term}'."}), 404

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=3010, debug=True)
