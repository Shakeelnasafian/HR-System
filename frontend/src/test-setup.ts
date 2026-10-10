import {configure} from '@testing-library/react'
// Parallel jsdom workers on slower machines can exceed the 1s default before async renders settle.
configure({asyncUtilTimeout:5000})
