import { createRequest, ClientWrapper } from '@paysera/http-client-common';

import Payment from '../entity/Payment';

class NamedScalarClient {
    /**
     * @param {ClientWrapper} client
     */
    constructor(client) {
        this.client = client;
    }

    /**
     * Create payment
     * POST /payments
     *
     * @param {Payment} payment
     * @return {Promise.<Payment>}
     */
    createPayment(payment) {
        const request = createRequest(
            'POST',
            `payments`,
            payment,
        );

        return this.client
            .performRequest(request)
            .then(data => new Payment(data));
    }

}

export default NamedScalarClient;
