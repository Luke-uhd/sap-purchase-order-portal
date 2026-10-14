<?php
declare(strict_types=1);

set_exception_handler(static function (Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
        'arquivo' => basename($e->getFile()),
        'linha' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
});

require dirname(__DIR__, 1) . '/src/Bootstrap.php';
use App\Auth;
Auth::requireLogin();$sap=Auth::sapSession();$write=(bool)(\App\app_config('write_enabled_companies')[$sap['companyDb']]??false);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?=\App\e(Auth::csrfToken())?>"><title>Pedidos de compra</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="container"><header class="page-header"><div><h1>Editor de linhas de pedidos</h1><p>Empresa: <strong><?=\App\e($sap['companyDb'])?></strong> · Usuário: <strong><?=\App\e($sap['userName'])?></strong><?php if(!$write): ?> · <span class="badge warning">Modo consulta</span><?php endif; ?></p></div><div class="calendar-header-actions"><button id="calendarButton" type="button" class="secondary" title="Configurar dias úteis e feriados">Calendário</button><a class="button secondary" href="logout.php">Sair</a></div></header><section class="card toolbar filters">
<label class="field compact">
<span>Nº do pedido</span>
<input id="filterDocNum" type="number" min="1" step="1" inputmode="numeric" placeholder="Ex.: 2260283">
</label>
<label class="field">
<span>Fornecedor</span>
<input id="filterSupplier" type="search" maxlength="100" placeholder="Código ou nome do fornecedor">
</label>
<label class="field compact">
<span>Data do pedido — De</span>
<input id="filterDocDateFrom" type="date" min="2000-01-01" max="2100-12-31">
</label>
<label class="field compact">
<span>Data do pedido — Até</span>
<input id="filterDocDateTo" type="date" min="2000-01-01" max="2100-12-31">
</label>
<label class="field compact">
<span>Distribuição — OcrCode</span>
<input id="filterCostingCode" type="text" maxlength="100"
       placeholder="Ex.: 1500" autocomplete="off">
</label>
<button id="filterButton" type="button">Filtrar</button>
<button id="clearFilterButton" type="button" class="secondary">Limpar filtros</button>
</section><section class="card toolbar"><button id="reloadButton" class="secondary">Recarregar</button><button id="previewButton" <?=$write?'':'disabled'?>>Prévia</button><button id="discardButton" class="danger">Descartar</button>
<button id="downloadTemplateButton" type="button" class="secondary">Baixar modelo XLSX</button>
<button id="importXlsxButton" type="button" class="secondary">Importar XLSX</button>
<input id="xlsxFileInput" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden><label class="field compact"><span>Pedidos por página</span><select id="pageSize"><option>10</option><option selected>20</option><option>30</option><option>50</option></select></label></section><section class="card toolbar pagination"><button id="previousButton" class="secondary">← Anterior</button><span id="pageInfo">Página 1</span><button id="nextButton" class="secondary">Próxima →</button></section><div id="summary" class="summary">Carregando...</div><pre id="message" class="message hidden"></pre><section class="card table-card"><table><thead><tr><th>Pedido</th><th>Fornecedor</th><th>Item</th><th>Descrição</th><th>Qtd atual</th><th>Nova qtd</th><th>Entrega atual</th><th>Nova entrega</th><th>Ação</th><th>Situação</th></tr></thead><tbody id="ordersBody"><tr><td colspan="10">Carregando...</td></tr></tbody></table></section></main><dialog id="previewDialog" class="preview-dialog"><form method="dialog"><h2>Prévia das alterações</h2><div id="previewContent"></div><menu><button value="cancel" class="secondary">Cancelar</button><button id="confirmPreviewButton" value="confirm" class="success">Confirmar aplicação</button></menu></form></dialog><script>window.APP={csrf:document.querySelector('meta[name="csrf-token"]').content,writeEnabled:<?=$write?'true':'false'?>};</script>
<dialog id="calendarDialog" aria-labelledby="calendarTitle">
    <div class="calendar-panel">
        <h2 id="calendarTitle">Calendário de dias úteis</h2>
        <p id="calendarCompany"></p>
        <p>
            Configuração compartilhada pelos usuários desta empresa.
            Salvar não altera pedidos existentes.
        </p>

        <fieldset id="calendarWeekdays">
            <legend>Dias considerados úteis</legend>
            <label><input type="checkbox" value="1"> Seg</label>
            <label><input type="checkbox" value="2"> Ter</label>
            <label><input type="checkbox" value="3"> Qua</label>
            <label><input type="checkbox" value="4"> Qui</label>
            <label><input type="checkbox" value="5"> Sex</label>
            <label><input type="checkbox" value="6"> Sáb</label>
            <label><input type="checkbox" value="7"> Dom</label>
        </fieldset>

        <h3>Feriados e dias sem expediente</h3>
        <p>
            “Anual” repete o dia e mês em todos os anos.
            Para datas móveis ou emendas, deixe essa opção desmarcada.
        </p>

        <div class="calendar-table">
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Anual</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody id="calendarRows"></tbody>
            </table>
        </div>

        <button id="calendarAdd" type="button" class="secondary">
            Adicionar data
        </button>

        <pre id="calendarMessage" role="status"></pre>
        <p id="calendarUpdated"></p>

        <div class="calendar-actions">
            <button id="calendarClose" type="button" class="secondary">
                Fechar
            </button>
            <button id="calendarSave" type="button">
                Salvar calendário
            </button>
        </div>
    </div>
</dialog>

<style>
#calendarButton { padding: .45rem .65rem; font-size: .85rem; }
.calendar-header-actions { display: flex; align-items: center; gap: .5rem; }
#calendarDialog {
    width: min(850px, 94vw);
    max-height: 90vh;
    overflow: auto;
    border: 1px solid #8795a5;
    border-radius: 10px;
    padding: 1.2rem;
}
#calendarDialog::backdrop { background: rgba(0, 0, 0, .45); }
#calendarWeekdays {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin: 1rem 0;
}
#calendarWeekdays label { display: inline-flex; align-items: center; gap: .3rem; }
#calendarDialog input[type="checkbox"] { width: auto; }
.calendar-table { overflow-x: auto; margin-bottom: .8rem; }
.calendar-table input[type="text"] { min-width: 220px; width: 100%; }
.calendar-actions { display: flex; justify-content: flex-end; gap: .6rem; }
#calendarMessage { white-space: pre-wrap; }
#calendarUpdated { font-size: .85rem; }
</style>
<script src="assets/orders.js"></script><script src="assets/calendar.js"></script></body></html