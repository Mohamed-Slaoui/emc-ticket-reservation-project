<?php

namespace App\Form\Security;

use App\DTO\RegistrationFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The self sign-up form — an ordinary account, always a customer.
 */
class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'username'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The email address is required.'),
                    new Assert\Email(message: 'The email address is not valid.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First name',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'given-name'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The first name is required.'),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last name',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'family-name'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The last name is required.'),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Password',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'new-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The password is required.'),
                    new Assert\Length(min: 8, minMessage: 'The password must be at least 8 characters long.'),
                ],
            ])
            ->add('passwordConfirm', PasswordType::class, [
                'label' => 'Confirm the password',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'new-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The password must be confirmed.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationFormData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'registration';
    }
}
