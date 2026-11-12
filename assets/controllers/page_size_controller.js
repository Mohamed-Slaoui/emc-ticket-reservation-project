import { Controller } from '@hotwired/stimulus';

/**
 * Applies the page size straight away.
 *
 * The selector sits inside the filter form of the list: when the visitor
 * chooses a size, the form is submitted with the other filters and the
 * list returns to its first page.
 */
export default class extends Controller {
    submit() {
        this.element.form?.requestSubmit();
    }
}
