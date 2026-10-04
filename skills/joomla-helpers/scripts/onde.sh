#!/usr/bin/env bash
# Mapa rápido do projeto Joomla atual (rode na pasta do projeto/worktree).
# Mostra: raiz do Joomla, versão, componentes, pastas com helpers e quais estão desatualizados.
set -uo pipefail
raiz="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
echo "pasta: $raiz"

# Raiz do Joomla: o próprio projeto, uma subpasta (ex.: public_html) ou nenhuma (repo só do componente).
joomla="$(find "$raiz" -maxdepth 3 -name configuration.php -not -path '*/vendor/*' 2>/dev/null \
    | while read -r c; do d="$(dirname "$c")"; [ -f "$d/includes/defines.php" ] && echo "$d"; done | head -1)"
if [ -n "$joomla" ]; then
    v="$(grep -hoE "(MAJOR_VERSION|RELEASE) *= *'?[0-9.]+" "$joomla/libraries/src/Version.php" "$joomla/libraries/cms/version/version.php" 2>/dev/null | grep -oE "[0-9.]+" | head -1)"
    echo "joomla: $joomla (versão ${v:-?})"
else
    echo "joomla: instalação não encontrada aqui (repositório só do componente?)"
fi

# Componentes nativos do Joomla 3/4/5 ficam de fora: interessa o seu.
NATIVOS="actionlogs admin ajax associations banners cache categories checkin config contact contenthistory content cpanel fields finder guidedtours installer joomlaupdate languages login mailto mails media menus messages modules newsfeeds plugins postinstall privacy redirect scheduler search tags templates users workflow wrapper"
echo "componentes (sem os nativos):"
find "$raiz" -maxdepth 6 -type d -name 'com_*' -not -path '*/vendor/*' -not -path '*/node_modules/*' \
    \( -path '*/components/*' -o -path "$raiz/com_*" \) 2>/dev/null \
    | while read -r d; do n="$(basename "$d")"; case " $NATIVOS " in *" ${n#com_} "*) ;; *) echo "  ${d#$raiz/}" ;; esac; done \
    | sort | head -20
find "$raiz" -maxdepth 3 -name '*.xml' -not -path '*/vendor/*' 2>/dev/null \
    | xargs grep -l 'type="component"' 2>/dev/null | sed "s#^$raiz/#  manifesto: #" | head -5

fonte="$(bash "$(dirname "$0")/_repo.sh" --fonte 2>/dev/null || true)"
echo "helpers no projeto:"
achou=0
while read -r f; do
    [ -n "$f" ] || continue; achou=1; d="$(dirname "$f")"
    lista=""
    for h in "$d"/*.php; do
        n="$(basename "$h" .php)"; [ -f "$fonte/$n.php" ] || continue
        if cmp -s "$h" "$fonte/$n.php"; then lista="$lista $n"; else lista="$lista $n*"; fi
    done
    echo "  ${d#$raiz/}:$lista"
done < <(find "$raiz" -maxdepth 7 \( -name OrmBase.php -o -name QueueHelper.php -o -name InputHelper.php -o -name ApiResponseHelper.php -o -name UploadMaster.php -o -name CsvHelper.php \) \
    -not -path '*/vendor/*' -not -path '*/node_modules/*' -not -path '*/.git/*' 2>/dev/null | xargs -r -n1 dirname | sort -u | sed 's#$#/x#')
[ $achou -eq 1 ] && echo "  (* = difere da versão oficial em ${fonte:-?})" || echo "  nenhum. Instale com: scripts/instalar.sh <componente>/helpers OrmTables ..."
echo "php: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'não encontrado')"
