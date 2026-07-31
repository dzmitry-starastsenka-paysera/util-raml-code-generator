import Payment from './entity/Payment';
import PaymentMetadata from './entity/PaymentMetadata';
import { Entity } from '@paysera/http-client-common';

import { createNamedScalarClient } from './service/createClient';
import NamedScalarClient from './service/NamedScalarClient';

export {
    Payment,
    PaymentMetadata,
    Entity,
    createNamedScalarClient,
    NamedScalarClient,
};
