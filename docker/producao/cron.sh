#!/bin/sh
# Tarefas agendadas de todas as empresas (docs/multi-tenancy.md, "Tarefas
# agendadas por empresa"): fila de e-mails a cada 2 minutos, reenvio a cada 5
# e backup de cada empresa uma vez por dia, às 3h (horário de Brasília).
# Roda como www-data (entrypoint.sh): o log do dia nunca nasce do root.
# A trava do tools tenants_tarefa impede duas execuções da mesma tarefa.

# Na subida (inclusive depois de cada deploy): banco mestre em dia e rotas do
# Traefik regeradas. Os dois comandos podem rodar quantas vezes for preciso.
until php /app/index.php tools tenant_mestre; do
    echo "banco mestre indisponível; nova tentativa em 10 s"
    sleep 10
done
php /app/index.php tools traefik_rotas || echo "rotas do Traefik não foram regeradas ($?)"

minuto=0
ultimo_backup=""
while true; do
    if [ $((minuto % 2)) -eq 0 ]; then
        php /app/index.php tools tenants_tarefa email process || echo "email process falhou ($?)"
    fi
    if [ $((minuto % 5)) -eq 0 ]; then
        php /app/index.php tools tenants_tarefa email retry || echo "email retry falhou ($?)"
    fi

    hoje=$(TZ=America/Sao_Paulo date +%Y-%m-%d)
    if [ "$(TZ=America/Sao_Paulo date +%H)" = "03" ] && [ "$ultimo_backup" != "$hoje" ]; then
        php /app/index.php tools tenants_backup || echo "backup diário falhou ($?)"
        ultimo_backup="$hoje"
    fi

    minuto=$((minuto + 1))
    sleep 60
done
