
"use strict";
(() => {
    const byId = id => document.getElementById(id);
    const button = byId("calendarButton");
    const dialog = byId("calendarDialog");
    const rows = byId("calendarRows");
    const weekdays = byId("calendarWeekdays");
    const status = byId("calendarMessage");
    const save = byId("calendarSave");
    const add = byId("calendarAdd");
    const close = byId("calendarClose");

    let revision = 0;
    let processing = false;
    let loaded = false;
    let dirty = false;

    function notify(text, error = false) {
        status.textContent = text;
        status.style.color = error ? "#b42318" : "";
    }

    function busy(value) {
        processing = value;
        button.disabled = value;
        close.disabled = value;
        save.disabled = value || !loaded;
        add.disabled = value || !loaded;
        dialog.querySelectorAll("input, .calendar-remove")
            .forEach(element => element.disabled = value || !loaded);
    }

    function addRow(holiday = {}) {
        const tr = document.createElement("tr");

        const date = document.createElement("input");
        date.type = "date";
        date.min = "2000-01-01";
        date.max = "2100-12-31";
        date.className = "calendar-date";
        date.value = holiday.date || "";

        const description = document.createElement("input");
        description.type = "text";
        description.maxLength = 250;
        description.className = "calendar-description";
        description.value = holiday.description || "";

        const annual = document.createElement("input");
        annual.type = "checkbox";
        annual.className = "calendar-annual";
        annual.checked = holiday.annual === true;

        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "secondary calendar-remove";
        remove.textContent = "Remover";
        remove.onclick = () => {
            tr.remove();
            dirty = true;
        };

        [date, description, annual, remove].forEach(element => {
            const td = document.createElement("td");
            td.appendChild(element);
            tr.appendChild(td);
        });

        rows.appendChild(tr);
    }

    async function request(options = {}) {
        const response = await fetch("./api/calendar.php", {
            cache: "no-store",
            ...options,
            headers: {
                Accept: "application/json",
                ...(options.headers || {})
            }
        });

        const text = await response.text();
        let result;

        try {
            result = JSON.parse(text);
        } catch {
            throw new Error(`Resposta inválida HTTP ${response.status}.`);
        }

        if (!response.ok || !result.ok) {
            throw new Error(result.erro || `Erro HTTP ${response.status}.`);
        }

        return result.calendar;
    }

    function populate(calendar) {
        revision = calendar.revision;
        rows.replaceChildren();

        weekdays.querySelectorAll("input").forEach(input => {
            input.checked = calendar.weekdays.includes(Number(input.value));
        });

        calendar.holidays.forEach(addRow);

        byId("calendarCompany").textContent =
            `Empresa: ${calendar.companyDb} · Versão: ${calendar.revision}`;

        byId("calendarUpdated").textContent = calendar.updatedAt
            ? `Última alteração: ${calendar.updatedAt} · ${calendar.updatedBy}`
            : "Calendário ainda não salvo.";

        loaded = true;
        dirty = false;
    }

    button.onclick = async () => {
        loaded = false;
        dirty = false;
        rows.replaceChildren();
        byId("calendarUpdated").textContent = "";
        byId("calendarCompany").textContent = "";
        dialog.showModal();
        busy(true);
        notify("Carregando calendário...");

        try {
            const calendar = await request();
            populate(calendar);
            notify(calendar.configured
                ? ""
                : "Primeiro cadastro: segunda a sexta estão selecionadas, "
                  + "mas nenhum feriado foi cadastrado. Revise e salve.");
        } catch (error) {
            notify(error.message, true);
        } finally {
            busy(false);
        }
    };

    dialog.addEventListener("input", () => {
        dirty = true;
    });

    add.onclick = () => {
        addRow();
        dirty = true;
    };

    function mayClose() {
        return !processing &&
            (!dirty || confirm("Fechar sem salvar as alterações do calendário?"));
    }

    close.onclick = () => {
        if (mayClose()) dialog.close();
    };

    dialog.addEventListener("cancel", event => {
        if (!mayClose()) event.preventDefault();
    });

    save.onclick = async () => {
        const selected = [...weekdays.querySelectorAll("input:checked")]
            .map(input => Number(input.value));

        const holidays = [...rows.querySelectorAll("tr")].map(tr => ({
            date: tr.querySelector(".calendar-date").value,
            description: tr.querySelector(".calendar-description").value.trim(),
            annual: tr.querySelector(".calendar-annual").checked
        }));

        if (!selected.length) {
            notify("Selecione pelo menos um dia útil.", true);
            return;
        }

        if (holidays.some(holiday => !holiday.date || !holiday.description)) {
            notify("Preencha data e descrição de todas as linhas.", true);
            return;
        }

        if (!confirm(
            "Salvar o calendário compartilhado desta empresa? "
            + "Os pedidos existentes não serão atualizados."
        )) return;

        busy(true);
        notify("Salvando...");

        try {
            const calendar = await request({
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": window.APP.csrf
                },
                body: JSON.stringify({
                    revision,
                    weekdays: selected,
                    holidays
                })
            });

            populate(calendar);

            const confirmPreview = byId("confirmPreviewButton");
            if (confirmPreview) confirmPreview.disabled = true;

            const previewDialog = byId("previewDialog");
            if (previewDialog?.open) previewDialog.close();

            notify("Calendário salvo. Gere uma nova prévia antes de aplicar pedidos.");
        } catch (error) {
            notify(error.message, true);
        } finally {
            busy(false);
        }
    };
})();
