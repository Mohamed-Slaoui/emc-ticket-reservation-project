import { Controller } from '@hotwired/stimulus';

/**
 * Shows or hides the box-office tariff permissions based on the selected
 * role. When the role is not ROLE_BOX_OFFICE the section carries no meaning
 * — an administrator already sells every tariff, a customer sells none.
 *
 * The role radios are found by their name, so this works regardless of how
 * the form theme renders them.
 */
export default class extends Controller {
    static targets = ['section'];

    connect() {
        this.element
            .querySelectorAll('input[type="radio"]')
            .forEach((input) => input.addEventListener('change', () => this.toggle()));
        this.toggle();
    }

    toggle() {
        const selected = this.element.querySelector('input[type="radio"]:checked');
        this.sectionTarget.hidden = !selected || selected.value !== 'ROLE_BOX_OFFICE';
    }
}
