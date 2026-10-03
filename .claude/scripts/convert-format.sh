#!/usr/bin/env bash
# convert-format.sh — Conversões determinísticas de formato/case (com convenções Laravel)
# Uso: convert-format.sh <operação> <input>
#
# Case genérico:
#   to-camel      "user_name"          → userName
#   to-snake      "userName"           → user_name
#   to-kebab      "userName"           → user-name
#   to-pascal     "user_name"          → UserName       (alias: to-studly)
#   to-upper      "abc"                → ABC
#   to-lower      "ABC"                → abc
#   slug          "Olá Mundo!"         → ola-mundo
#
# Convenções Laravel (Model ↔ tabela ↔ rota):
#   to-table      "BlogPost"           → blog_posts            (nome da tabela do Model)
#   to-model      "blog_posts"         → BlogPost              (Model a partir da tabela)
#   to-foreign    "BlogPost"           → blog_post_id          (coluna de chave estrangeira)
#   to-pivot      "Tag Post"           → post_tag              (tabela pivô, ordem alfabética)
#   to-route      "BlogPost"           → blog-posts            (URI de Route::resource)
#   to-view       "BlogPost"           → blog-posts            (pasta de views: resources/views/blog-posts)
#   to-component  "PricingCard"        → pricing-card          (<x-pricing-card>)
#   to-migration  "blog_posts"         → create_blog_posts_table
#   to-plural     "category"           → categories
#   to-singular   "categories"         → category
#
# Formatos de arquivo:
#   json-yaml     <file.json>          → YAML stdout
#   yaml-json     <file.yaml>          → JSON stdout
#   csv-json      <file.csv>           → JSON stdout
#
# Pluralização: se o diretório atual for um projeto Laravel com vendor/ instalado,
# usa Illuminate\Support\Str (mesmo inflector do framework). Senão, regras em
# inglês simplificadas (y→ies, s/x/z/ch/sh→es, irregulares comuns).

set -euo pipefail

OP="${1:?Uso: convert-format.sh <operação> <input>. Veja header para lista.}"
shift
INPUT="${*:-}"

# Normaliza qualquer entrada (camel, Pascal, kebab, espaços) para snake_case
snake_of() {
  echo "$1" | sed -E 's/([a-z0-9])([A-Z])/\1_\2/g; s/([A-Z]+)([A-Z][a-z])/\1_\2/g; s/[- ]+/_/g' | tr '[:upper:]' '[:lower:]'
}

to_camel()  { snake_of "$INPUT" | awk -F'_' '{out=$1; for(i=2;i<=NF;i++) out=out toupper(substr($i,1,1)) substr($i,2); print out}'; }
to_snake()  { snake_of "$INPUT"; }
to_kebab()  { snake_of "$INPUT" | tr '_' '-'; }
pascal_of() { snake_of "$1" | awk -F'_' '{for(i=1;i<=NF;i++) printf "%s", toupper(substr($i,1,1)) substr($i,2); print ""}'; }
to_pascal() { pascal_of "$INPUT"; }
to_upper()  { echo "$INPUT" | tr '[:lower:]' '[:upper:]'; }
to_lower()  { echo "$INPUT" | tr '[:upper:]' '[:lower:]'; }

slug() {
  # Python lida melhor com unicode que iconv no macOS (equivale a Str::slug)
  python3 -c "
import sys, unicodedata, re
s = unicodedata.normalize('NFKD', sys.argv[1]).encode('ascii', 'ignore').decode('ascii')
s = re.sub(r'[^a-zA-Z0-9]+', '-', s).strip('-').lower()
print(s)
" "$INPUT"
}

# Inflector: usa o Str do Laravel quando disponível
laravel_str() {
  local method="$1" word="$2"
  if [[ -f vendor/autoload.php ]] && command -v php >/dev/null 2>&1; then
    php -r 'require "vendor/autoload.php"; echo Illuminate\Support\Str::{$argv[1]}($argv[2]), PHP_EOL;' "$method" "$word" 2>/dev/null && return 0
  fi
  return 1
}

inflect() {
  local mode="$1" word="$2"
  python3 - "$mode" "$word" <<'PY'
import re, sys
mode, word = sys.argv[1], sys.argv[2]
irregular = {'person': 'people', 'man': 'men', 'woman': 'women', 'child': 'children',
             'tooth': 'teeth', 'foot': 'feet', 'mouse': 'mice', 'goose': 'geese',
             'status': 'statuses', 'quiz': 'quizzes', 'index': 'indices', 'datum': 'data'}
uncountable = {'equipment', 'information', 'rice', 'money', 'species', 'series', 'fish',
               'sheep', 'news', 'feedback', 'metadata', 'data'}
low = word.lower()
if low in uncountable:
    print(word); sys.exit()
if mode == 'plural':
    if low in irregular: print(irregular[low]); sys.exit()
    if re.search(r'[^aeiou]y$', low): print(word[:-1] + 'ies')
    elif re.search(r'(s|x|z|ch|sh)$', low): print(word + 'es')
    elif re.search(r'(?:[^f]fe|[lr]f)$', low): print(re.sub(r'fe?$', 'ves', word))
    else: print(word + 's')
else:
    inv = {v: k for k, v in irregular.items()}
    if low in inv: print(inv[low]); sys.exit()
    if low.endswith('ies'): print(word[:-3] + 'y')
    elif re.search(r'(ses|xes|zes|ches|shes)$', low): print(word[:-2])
    elif low.endswith('ves'): print(word[:-3] + 'f')
    elif low.endswith('s') and not low.endswith('ss'): print(word[:-1])
    else: print(word)
PY
}

plural()   { laravel_str plural "$1"   || inflect plural "$1"; }
singular() { laravel_str singular "$1" || inflect singular "$1"; }

# Pluraliza só a última palavra de um snake_case: blog_post → blog_posts
plural_snake() {
  local s="$1" head last
  if [[ "$s" == *_* ]]; then head="${s%_*}_"; last="${s##*_}"; else head=""; last="$s"; fi
  echo "${head}$(plural "$last")"
}
singular_snake() {
  local s="$1" head last
  if [[ "$s" == *_* ]]; then head="${s%_*}_"; last="${s##*_}"; else head=""; last="$s"; fi
  echo "${head}$(singular "$last")"
}

case "$OP" in
  to-camel)            to_camel ;;
  to-snake)            to_snake ;;
  to-kebab)            to_kebab ;;
  to-pascal|to-studly) to_pascal ;;
  to-upper)            to_upper ;;
  to-lower)            to_lower ;;
  slug)                slug ;;
  to-table)            plural_snake "$(snake_of "$INPUT")" ;;
  to-model)            pascal_of "$(singular_snake "$(snake_of "$INPUT")")" ;;
  to-foreign)          echo "$(singular_snake "$(snake_of "$INPUT")")_id" ;;
  to-pivot)
    # Laravel: nomes no singular, snake_case, em ordem alfabética
    read -r -a parts <<< "$(echo "$INPUT" | tr ',' ' ')"
    [[ ${#parts[@]} -ne 2 ]] && { echo "ERRO: to-pivot precisa de 2 models, ex: \"Post Tag\"" >&2; exit 1; }
    a=$(singular_snake "$(snake_of "${parts[0]}")"); b=$(singular_snake "$(snake_of "${parts[1]}")")
    printf '%s\n%s\n' "$a" "$b" | sort | paste -sd_ - ;;
  to-route|to-view)    plural_snake "$(snake_of "$INPUT")" | tr '_' '-' ;;
  to-component)        to_kebab ;;
  to-migration)        echo "create_$(plural_snake "$(snake_of "$INPUT")")_table" ;;
  to-plural)           plural "$INPUT" ;;
  to-singular)         singular "$INPUT" ;;
  json-yaml)
    if command -v yq >/dev/null 2>&1; then yq -P "$INPUT"
    else python3 -c "import json,sys,yaml;print(yaml.safe_dump(json.load(open(sys.argv[1])),allow_unicode=True,sort_keys=False))" "$INPUT"; fi ;;
  yaml-json)
    if command -v yq >/dev/null 2>&1; then yq -o=json "$INPUT"
    else python3 -c "import json,sys,yaml;print(json.dumps(yaml.safe_load(open(sys.argv[1])),indent=2,ensure_ascii=False))" "$INPUT"; fi ;;
  csv-json)
    python3 -c "import csv,json,sys;print(json.dumps(list(csv.DictReader(open(sys.argv[1]))),indent=2,ensure_ascii=False))" "$INPUT" ;;
  *)
    echo "ERRO: operação desconhecida '$OP'" >&2
    exit 1 ;;
esac
