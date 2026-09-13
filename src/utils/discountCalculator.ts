
// original price - offer price = discount percentage
export const calculateDiscountPercentage = (originalPrice: number, offerPrice: number) => {
    const discountPercentage = ((originalPrice - offerPrice) / originalPrice) * 100;
    return discountPercentage;
}

// original price - offer price = discount amount
export const calculateDiscountAmount = (originalPrice: number, offerPrice: number) => {
    const discountAmount = originalPrice - offerPrice;
    return discountAmount;
}

// discount percentage * original price = discount amount
export const calculateDiscountAmountFromPercentage = (discountPercentage: number, originalPrice: number) => {
    const discountAmount = (discountPercentage / 100) * originalPrice;
    return discountAmount;
}

// discount amount / original price = discount percentage
export const calculateDiscountPercentageFromAmount = (discountAmount: number, originalPrice: number) => {
    const discountPercentage = (discountAmount / originalPrice) * 100;
    return discountPercentage;
}

// original price - discount amount = offer price
export const calculateOfferPrice = (originalPrice: number, discountAmount: number) => {
    const offerPrice = originalPrice - discountAmount;
    return offerPrice;
}

// original price / installment number
export const calculateInstallmentPrice = (originalPrice: number, installmentNumber: number) => {
    const installmentPrice = originalPrice / installmentNumber;
    return installmentPrice;
}