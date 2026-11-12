import { Controller } from '@hotwired/stimulus';

/**
 * The seat map of a configuration.
 *
 * The map holds one checkbox per seat of the room — four hundred of them.
 * Selecting a whole row, or the whole room, saves the administrator from
 * ticking the seats one by one.
 */
export default class extends Controller {
    static targets = ['row'];

    toggleRow(event) {
        const checkbox = event.currentTarget;
        const row = checkbox.closest('[data-seat-map-target="row"]');

        if (!row) {
            return;
        }

        row.querySelectorAll('.seat__input').forEach((seat) => {
            seat.checked = checkbox.checked;
        });
    }

    toggleAll(event) {
        this.element.querySelectorAll('.seat__input').forEach((seat) => {
            seat.checked = event.currentTarget.checked;
        });
    }
}
