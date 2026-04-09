import { Elements } from "@stripe/react-stripe-js";
import { loadStripe } from "@stripe/stripe-js";

const stripeKey = import.meta.env.VITE_STRIPE_PUBLIC_KEY;
const isValidKey = stripeKey && stripeKey.startsWith("pk_") && !stripeKey.includes("placeholder");
const stripePromise = isValidKey ? loadStripe(stripeKey) : null;

const StripeProvider = ({ children }) => {
  if (!stripePromise) {
    return <div>Stripe is not configured. Please contact support.</div>;
  }

  return (
    <Elements stripe={stripePromise}>
      {children}
    </Elements>
  );
};

export default StripeProvider;