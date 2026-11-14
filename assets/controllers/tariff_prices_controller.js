import { Controller } from '@hotwired/stimulus';

/**
 * The tariff picker of the box-office sell page — makes seat prices follow
 * the chosen tariff. The zone × tariff map is embedded as JSON; changing
 * the radio reprices every seat, the zone legend and the running total
 * (the booking controller reacts to the synthetic change).
 */
export default class extends Controller {
    connect() {
        const island = document.getElementById('zone-prices-by-tariff');
        this.prices = island ? JSON.parse(island.textContent || '{}') : {};
        this.element.addEventListener('change', (event) => {
            if (event.target.matches('input[name="tariff"]')) {
                this.apply();
            }
        });
        this.apply();
    }

    apply() {
        const checked = this.element.querySelector('input[name="tariff"]:checked');

        if (!checked) {
            return;
        }

        const matrix = this.prices[checked.value] || {};

        this.element.querySelectorAll('[data-zone-price]').forEach((span) => {
            const amount = matrix[span.dataset.zonePrice];
            span.textContent = amount ? `${amount} MAD` : '—';
        });

        this.element.querySelectorAll('.seat__input').forEach((input) => {
            input.dataset.price = matrix[input.dataset.zone] || '0.00';
        });

        const ping = this.element.querySelector('.seat__input');

        if (ping) {
            ping.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}
